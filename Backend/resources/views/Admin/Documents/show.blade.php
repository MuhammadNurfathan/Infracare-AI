<x-app-layout>

    <x-slot name="header">
        <h2 class="font-semibold text-xl">
            Detail Manual Book
        </h2>
    </x-slot>

    <div class="py-12">

        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8">

            <div class="bg-white rounded shadow">

                <div class="p-6">

                    <div class="mb-5">

                        <strong>Judul :</strong>

                        {{ $document->title }}

                    </div>

                    <div class="mb-5">

                        <strong>Status :</strong>

                        {{ $document->status }}

                    </div>

                    <div class="mb-5">

                        <strong>Tipe :</strong>

                        {{ strtoupper($document->file_type) }}

                    </div>

                    <div class="mb-5">

                        <strong>Isi Manual :</strong>

                        <textarea
                            rows="20"
                            class="w-full border rounded p-3"
                            readonly>{{ $document->content }}</textarea>

                    </div>

                    <a href="{{ route('documents.index') }}"
                        class="bg-blue-600 text-white px-5 py-2 rounded">

                        Kembali

                    </a>

                </div>

            </div>

        </div>

    </div>

</x-app-layout>