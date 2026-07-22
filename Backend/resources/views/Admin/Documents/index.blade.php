<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Knowledge Base') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">

            @if(session('success'))
                <div class="mb-4 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded">
                    {{ session('success') }}
                </div>
            @endif

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">

                <div class="p-6">

                    <div class="flex justify-between mb-5">

                        <h3 class="text-lg font-semibold">
                            Manual Book
                        </h3>

                        <a href="{{ route('documents.create') }}"
                            class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded">
                            Upload Manual Book
                        </a>

                    </div>

                    <table class="min-w-full border">

                        <thead class="bg-gray-100">

                            <tr>

                                <th class="border px-3 py-2">No</th>

                                <th class="border px-3 py-2">Judul</th>

                                <th class="border px-3 py-2">Status</th>

                                <th class="border px-3 py-2">Type</th>

                                <th class="border px-3 py-2">Action</th>

                            </tr>

                        </thead>

                        <tbody>

                            @forelse($documents as $document)

                                <tr>

                                    <td class="border px-3 py-2">
                                        {{ $loop->iteration }}
                                    </td>

                                    <td class="border px-3 py-2">
                                        {{ $document->title }}
                                    </td>

                                    <td class="border px-3 py-2">
                                        {{ $document->status }}
                                    </td>

                                    <td class="border px-3 py-2">
                                        {{ strtoupper($document->file_type) }}
                                    </td>

                                   <td class="border px-3 py-2 text-center">
    <div class="flex justify-center items-center gap-2">
        <a href="{{ route('documents.show', $document) }}"
           class="bg-green-500 hover:bg-green-600 text-white px-3 py-1 rounded">
            View
        </a>

        <form action="{{ route('documents.destroy', $document) }}" method="POST">
            @csrf
            @method('DELETE')

            <button type="submit"
                    onclick="return confirm('Hapus manual book ini?')"
                    class="bg-red-500 hover:bg-red-600 text-white px-3 py-1 rounded">
                Delete
            </button>
        </form>
    </div>
</td>

                                </tr>

                            @empty

                                <tr>

                                    <td colspan="5"
                                        class="text-center py-5">

                                        Belum ada manual book.

                                    </td>

                                </tr>

                            @endforelse

                        </tbody>

                    </table>

                </div>

            </div>

        </div>

    </div>

</x-app-layout>