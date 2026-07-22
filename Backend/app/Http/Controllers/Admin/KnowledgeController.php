<?php

namespace App\Http\Controllers;

use App\Services\Document\DocumentServiceInterface;

class KnowledgeController extends Controller
{
    public function __construct(
        private DocumentServiceInterface $documentService
    ) {
    }

    public function index()
    {
        dd($this->documentService);
    }
}