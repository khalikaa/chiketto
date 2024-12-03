@extends('layouts.app')
@section('title', 'Explore Tickets')
@section('content')
    {{-- <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Daftar Event') }}
        </h2>
    </x-slot> --}}
    @if (Route::currentRouteName() === 'homepage' || Route::currentRouteName() === 'explore-events')
        <img src="/chi-header.png" alt="">
    @endif

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 bg-white border-b border-gray-200">
                    <h1 class="text-2xl font-bold mb-6">Daftar Event</h1>

                    @yield('add_event')

                    @if (Route::currentRouteName() === 'manage-events' || Route::currentRouteName() === 'my-events')
                        <a href="{{ route('events.create') }}"
                            class="bg-blue-500 text-white px-4 py-2 rounded hover:bg-blue-600 mb-6 inline-block">
                            Buat Event Baru
                        </a>
                        @if (session('success'))
                            <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6">
                                {{ session('success') }}
                            </div>
                        @endif
                    @endif
                    @if ($events->isEmpty())
                        <p class="text-gray-500">Tidak ada event yang tersedia.</p>
                    @else
                        <div class="overflow-x-auto">
                            <table class="min-w-full bg-white border border-gray-300">
                                <thead class="bg-gray-200">
                                    <tr>
                                        <th class="px-4 py-2 text-left border-b">#</th>
                                        <th class="px-4 py-2 text-left border-b">Nama Event</th>
                                        <th class="px-4 py-2 text-left border-b">Deskripsi</th>
                                        <th class="px-4 py-2 text-left border-b">Lokasi</th>
                                        <th class="px-4 py-2 text-left border-b">Tanggal</th>
                                        <th class="px-4 py-2 text-left border-b">Waktu</th>
                                        {{-- <th class="px-4 py-2 text-left border-b">Harga Tiket</th>
                                        <th class="px-4 py-2 text-left border-b">Kuota Event</th> --}}
                                        <th class="px-4 py-2 text-left border-b">Gambar</th>
                                        <th class="px-4 py-2 text-left border-b">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($events as $event)
                                        <tr class="border-b">
                                            <td class="px-4 py-2">{{ $loop->iteration }}</td>
                                            <td class="px-4 py-2">{{ $event->name }}</td>
                                            <td class="px-4 py-2">{{ $event->description }}</td>
                                            {{-- <td class="px-4 py-2">{{ $event->quota }}</td> --}}
                                            <td class="px-4 py-2">
                                                {{ \Carbon\Carbon::parse($event->date_time)->format('d/m/Y') }}</td>
                                            <td class="px-4 py-2">
                                                {{ \Carbon\Carbon::parse($event->date_time)->format('H:i') }}</td>
                                            <td class="px-4 py-2">{{ $event->location }}</td>
                                            {{-- <td class="px-4 py-2">Rp{{ $event->ticket_price }}</td> --}}
                                            <td class="p/x-4 py-2">
                                                @if ($event->image_path)
                                                    <img src="{{ asset('storage/' . $event->image_path) }}"
                                                        alt="Event Image" class="w-16 h-16 object-cover">
                                                @else
                                                    <span class="text-gray-500">Tidak ada gambar</span>
                                                @endif
                                            </td>
                                            <td class="px-4 py-2">
                                                <a href="{{ route('events.show', $event->id) }}"
                                                    class="text-blue-500 hover:underline">Detail</a>
                                                @if (Route::currentRouteName() === 'manage-events' || Route::currentRouteName() === 'my-events')
                                                    <a href="{{ route('events.edit', $event->id) }}"
                                                        class="text-yellow-500 hover:underline">Edit</a>
                                                    <form action="{{ route('events.destroy', $event->id) }}" method="POST"
                                                        class="inline-block">
                                                        @csrf
                                                        @method('DELETE')
                                                        <button type="submit"
                                                            class="text-red-500 hover:underline">Delete</button>
                                                    </form>
                                                @endif
                                                @yield('edit_delete')
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection