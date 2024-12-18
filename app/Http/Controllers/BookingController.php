<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Event;
use App\Models\Booking;
use App\Models\BookingDetail;
use App\Models\Revenue;
use App\Models\Ticket;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class BookingController extends Controller
{
    /**
     * Display the bookings for the user.
     */
    public function index(Request $request)
    {
        $user = Auth::user();

        // Ambil status dari query parameter (default 'all')
        $status = $request->query('status', 'all');

        // Query dasar bookings
        $bookingsQuery = Booking::with('bookingDetail.ticket', 'event')->latest();

        if ($user->role === 'customer') {
            // Jika user adalah customer, hanya ambil booking yang dia buat
            $bookingsQuery->where('user_id', $user->id);
        } elseif ($user->role === 'organizer') {
            // Jika user adalah organizer, ambil booking untuk event yang dia buat
            $bookingsQuery->whereHas('event', function ($q) use ($user) {
                $q->where('user_id', $user->id);
            });
        }

        // Filter berdasarkan status jika status bukan 'all'
        if ($status !== 'all') {
            $bookingsQuery->where('status', $status);
        }

        // Ambil data bookings dengan pagination
        $bookings = $bookingsQuery->paginate(10);

        // Tentukan view berdasarkan peran
        $view = match ($user->role) {
            'customer' => 'customer.bookings',
            'organizer' => 'organizer.manage-orders',
            'admin' => 'admin.manage-orders',
            default => abort(403, 'Unauthorized action.'),
        };

        return view($view, compact('bookings', 'status'));
    }

    public function show($id)
    {
        // Fetch the booking with its related details
        $booking = Booking::with(['bookingDetail.ticket', 'event'])
            ->findOrFail($id);

        // Check if the user has access (optional)
        // if (Auth::id() !== $booking->user_id) {
        //     abort(403, 'Unauthorized access');
        // }

        return view('bookings.show', compact('booking'));
    }


    /**
     * Show the ticket selection page.
     */
    public function select(Request $request)
    {
        // Validate the request
        $request->validate([
            'event_id' => 'required|exists:events,id',
            'tickets' => 'required|array',
            'tickets.*' => 'integer|min:0',
        ]);

        // Filter tickets to ensure at least one is selected
        $tickets = array_filter($request->tickets, fn($quantity) => $quantity > 0);
        if (empty($tickets)) {
            return back()->with('error', 'Please select at least one ticket.');
        }

        // Validate total ticket quantity
        if (array_sum($tickets) > 5) {
            return back()->with('error', 'You can only purchase up to 5 tickets per transaction.');
        }

        // Fetch the event
        $event = Event::with('tickets')->findOrFail($request->event_id);

        // Store selected tickets in session and redirect
        $request->session()->put('selected_tickets', $tickets);
        return redirect()->route('bookings.create', ['event_id' => $event->id]);
    }

    /**
     * Show the ticket confirmation page.
     */
    public function create(Request $request)
    {
        // Validate the request
        $request->validate([
            'event_id' => 'required|exists:events,id',
        ]);

        // Fetch the event and tickets
        $event = Event::with('tickets')->findOrFail($request->event_id);

        // Get selected tickets from the session
        $selectedTickets = collect($request->session()->get('selected_tickets', []))->map(function ($quantity, $ticketId) use ($event) {
            $ticket = $event->tickets->find($ticketId);
            if (!$ticket) {
                abort(404, 'Ticket not found');
            }
            return [
                'id' => $ticket->id,
                'name' => $ticket->name,
                'description' => $ticket->description,
                'price' => $ticket->price,
                'quantity' => $quantity,
                'subtotal' => $ticket->price * $quantity,
            ];
        });

        $totalPrice = $selectedTickets->sum('subtotal');

        return view('bookings.create', compact('event', 'selectedTickets', 'totalPrice'));
    }

    /**
     * Store the booking in the database.
     */
    public function store(Request $request)
    {
        $request->validate([
            'holders' => 'required|array',
            'holders.*.*.name' => 'required|string|max:255',
            'holders.*.*.email' => 'required|email|max:255',
            'holders.*.*.gender' => 'required|in:male,female',
        ]);

        if (!Auth::check()) {
            return redirect()->route('login')->withErrors(['message' => 'You must be logged in to book tickets.']);
        }

        $validTicketIds = Event::findOrFail($request->event_id)->tickets->pluck('id')->toArray();

        foreach ($request->holders as $ticketId => $holders) {
            if (!in_array($ticketId, $validTicketIds)) {
                return redirect()->back()->withErrors(['message' => 'Invalid ticket ID.']);
            }
        }

        DB::beginTransaction();

        try {
            // Create the booking
            $booking = Booking::create([
                'user_id' => Auth::user()->id,
                'event_id' => $request->event_id,
                'status' => 'pending',
            ]);

            // Loop through ticket details and create BookingDetail
            foreach ($request->holders as $ticketId => $holders) {
                $ticket = Ticket::findOrFail($ticketId);

                // Check if the ticket has enough stock
                if ($ticket->quota < count($holders)) {
                    return redirect()->back()->withErrors(['message' => "Not enough stock for ticket: {$ticket->name}"]);
                }

                // Reduce the ticket quota
                $ticket->quota -= count($holders);
                $ticket->save();

                foreach ($holders as $holder) {
                    BookingDetail::create([
                        'booking_id' => $booking->id,
                        'ticket_id' => $ticketId,
                        'name' => $holder['name'],
                        'email' => $holder['email'],
                        'gender' => $holder['gender']
                    ]);
                }
            }

            DB::commit();

            return redirect()->route('bookings.index')->with('success', 'Your tickets have been booked successfully!');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Booking failed', ['error' => $e->getMessage()]);
            return redirect()->back()
                ->withInput($request->all())
                ->withErrors(['message' => 'An unexpected error occurred. Please try again.']);
        }
    }

    /**
     * Accept the booking and generate ticket codes.
     */
    public function accept($id)
    {
        $booking = Booking::with('bookingDetail.ticket', 'event')->findOrFail($id);

        // Ensure the current user is the organizer of the event
        if (Auth::id() !== $booking->event->user_id && Auth::user()->role !== 'admin') {
            return redirect()->back()->withErrors(['message' => 'Unauthorized action']);
        }

        DB::beginTransaction();

        try {
            $totalRevenue = 0;

            // Generate ticket code and update booking status
            foreach ($booking->bookingDetail as $detail) {
                $ticket = $detail->ticket;

                // Generate ticket code
                do {
                    $ticketCode = strtoupper(Str::random(10));
                } while (BookingDetail::where('ticket_code', $ticketCode)->exists());

                // Update BookingDetail with the ticket code
                $detail->update(['ticket_code' => $ticketCode]);

                // Add to total revenue
                $totalRevenue += $ticket->price;
            }

            // Update booking status to active
            $booking->update(['status' => 'active']);

            // Record revenue
            Revenue::create([
                'amount' => $totalRevenue,
                'booking_id' => $booking->id,
            ]);

            DB::commit();

            return redirect()->back()->with('success', 'Booking has been accepted and ticket codes have been generated.');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Booking acceptance failed', ['error' => $e->getMessage()]);

            return redirect()->back()
                ->withErrors(['message' => 'An unexpected error occurred. Please try again.']);
        }
    }

    /**
     * Cancel the booking and restore the ticket quota.
     */
    public function cancel($id)
    {
        $booking = Booking::findOrFail($id);

        // Ensure the current user is the organizer of the event
        if (Auth::id() !== $booking->event->user_id && Auth::id() !== $booking->user_id && Auth::user()->role !== 'admin') {
            return redirect()->back()->withErrors(['message' => 'Unauthorized action']);
        }

        DB::beginTransaction();

        try {
            // Restore ticket quota
            foreach ($booking->bookingDetail as $detail) {
                $ticket = $detail->ticket;
                $ticket->quota += 1;
                $ticket->save();
            }

            // Update booking status to canceled
            $booking->update(['status' => 'canceled']);

            DB::commit();

            return redirect()->back()->with('success', 'Booking has been canceled, and ticket quotas have been restored.');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Booking cancellation failed', ['error' => $e->getMessage()]);

            return redirect()->back()
                ->withErrors(['message' => 'An unexpected error occurred. Please try again.']);
        }
    }
}
