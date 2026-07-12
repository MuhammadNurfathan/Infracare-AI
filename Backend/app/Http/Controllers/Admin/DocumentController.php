<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Document\DocumentServiceInterface;
use App\Http\Requests\StoreDocumentRequest;
use App\Models\Document;

class DocumentController extends Controller
{
    public function __construct(
        private DocumentServiceInterface $documentService
    ) {
    }

    public function index()
    {
        $documents = $this->documentService->getAll();

        return view('admin.documents.index', compact('documents'));
    }

    public function create()
    {
        return view('admin.documents.create');
    }

    public function store(StoreDocumentRequest $request)
    {
        $this->documentService->upload(
            $request->validated()
        );

        return redirect()
            ->route('documents.index')
            ->with('success', 'Manual book berhasil diupload.');
    }

    public function show(Document $document)
    {
        return view('admin.documents.show', compact('document'));
    }

    public function destroy(Document $document)
    {
        $this->documentService->delete($document);

        return redirect()
            ->route('documents.index')
            ->with('success', 'Manual book berhasil dihapus.');
    }
}