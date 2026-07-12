<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Upload Manual Book
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">

            <div class="bg-white shadow-sm rounded-lg">

                <div class="p-6">

                    <form action="{{ route('documents.store') }}"
                          method="POST"
                          enctype="multipart/form-data">

                        @csrf

                        <div class="mb-5">

                            <label class="block font-medium mb-2">
                                Judul Manual
                            </label>

                            <input
                                type="text"
                                name="title"
                                value="{{ old('title') }}"
                                class="w-full border rounded px-3 py-2">

                            @error('title')
                                <small class="text-red-500">{{ $message }}</small>
                            @enderror

                        </div>

                        <div class="mb-5">

                            <label class="block font-medium mb-2">
                                File PDF
                            </label>

                            <input
                                type="file"
                                name="document"
                                accept=".pdf"
                                class="w-full border rounded px-3 py-2">

                            @error('document')
                                <small class="text-red-500">{{ $message }}</small>
                            @enderror

                        </div>

                        <div class="flex gap-3">

                            <button
                                class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2 rounded">

                                Upload

                            </button>

                            <a href="{{ route('documents.index') }}"
                               class="bg-gray-500 hover:bg-gray-600 text-white px-5 py-2 rounded">

                                Kembali

                            </a>

                        </div>

                    </form>

                </div>

            </div>

        </div>
    </div>

</x-app-layout>