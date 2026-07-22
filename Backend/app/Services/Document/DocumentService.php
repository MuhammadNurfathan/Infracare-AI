<?php

namespace App\Services\Document;

use App\Models\Document;
use App\Models\KnowledgeChunk;
use App\Repositories\DocumentRepositoryInterface;
use App\Services\Knowledge\KnowledgeServiceInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Smalot\PdfParser\Parser;

class DocumentService implements DocumentServiceInterface
{
    /**
     * A chunk larger than this gets split further (still keeping the section
     * heading attached to every part), so one giant procedure doesn't become
     * a single unsearchable wall of text.
     */
    private const MAX_CHUNK_LENGTH = 3000;

    /**
     * Below this many detected headings, the document is treated as
     * "not really structured" and falls back to the old fixed-size chunking
     * — safer than forcing a heading-split on a document that doesn't
     * actually have numbered section headings.
     */
    private const MIN_HEADINGS_TO_USE_SECTION_SPLIT = 3;

    /**
     * Below this many characters of actual body text (excluding the heading
     * line itself), a "section" is almost certainly a heading-only entry from
     * a chapter's mini table-of-contents (e.g. "1.1 Configuring the
     * Browser" listed again right before the real section starts) rather
     * than real content — skip it instead of storing a near-empty duplicate.
     */
    private const MIN_SECTION_BODY_LENGTH = 40;

    public function __construct(
        private DocumentRepositoryInterface $documentRepository,
        private KnowledgeServiceInterface $knowledgeService
    ) {
    }

    public function upload(array $data): Document
    {
        return DB::transaction(function () use ($data) {

            /*
            |--------------------------------------------------------------------------
            | Upload File
            |--------------------------------------------------------------------------
            */

            $file = $data['document'];

            $fileName = Str::uuid() . '.' . $file->getClientOriginalExtension();

            $filePath = $file->storeAs(
                'documents',
                $fileName,
                'public'
            );

            /*
            |--------------------------------------------------------------------------
            | Parse PDF
            |--------------------------------------------------------------------------
            */

            $parser = new Parser();

            $pdf = $parser->parseFile(
                storage_path('app/public/' . $filePath)
            );

            $content = $pdf->getText();

            /*
            |--------------------------------------------------------------------------
            | Clean Text
            |--------------------------------------------------------------------------
            */

            $content = $this->cleanText($content);

            /*
            |--------------------------------------------------------------------------
            | Save Document
            |--------------------------------------------------------------------------
            */

            $document = $this->documentRepository->create([

                'title'         => $data['title'],
                'file_name'     => $fileName,
                'file_path'     => $filePath,
                'content'       => $content,
                'file_type'     => $file->getClientOriginalExtension(),
                'total_chunks'  => 0,
                'status'        => 'uploaded',

            ]);

            /*
            |--------------------------------------------------------------------------
            | Delete Old Chunk
            |--------------------------------------------------------------------------
            */

            KnowledgeChunk::where(
                'document_id',
                $document->id
            )->delete();

            /*
            |--------------------------------------------------------------------------
            | Split Chunk
            |--------------------------------------------------------------------------
            */

            $chunks = $this->splitIntoChunks($content);

            foreach ($chunks as $index => $chunk) {

                KnowledgeChunk::create([

                    'document_id'  => $document->id,
                    'chunk_number' => $index + 1,
                    'content'      => $chunk,
                    'embedding'    => null,

                ]);

            }

            /*
            |--------------------------------------------------------------------------
            | Update Total Chunk
            |--------------------------------------------------------------------------
            */

            $document->update([
                'total_chunks' => count($chunks)
            ]);

            /*
            |--------------------------------------------------------------------------
            | Process Knowledge
            |--------------------------------------------------------------------------
            | Validates the chunks that were just created are actually usable and
            | flips the document's status to 'processed' (or 'failed' if parsing
            | produced nothing searchable) — see KnowledgeService for details.
            | This runs inside the same DB transaction as everything above, so a
            | failed/empty document never gets left in a half-finished state.
            */

            $this->knowledgeService->process($document);

            return $document->fresh();
        });
    }

    /**
     * Bersihkan text hasil parser PDF.
     *
     * NOTE: this keeps line breaks (unlike the old version, which collapsed
     * everything — including newlines — into single spaces). Section-heading
     * detection below relies on headings sitting on their own line, so
     * destroying line breaks here would make that impossible.
     */
    private function cleanText(string $text): string
    {
        $text = mb_convert_encoding(
            $text,
            'UTF-8',
            'auto'
        );

        $text = iconv(
            'UTF-8',
            'UTF-8//IGNORE',
            $text
        );

        // Page-break form-feed characters -> paragraph break.
        $text = str_replace("\f", "\n\n", $text);

        // Strip control characters but keep newlines.
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', ' ', $text) ?? $text;

        // Word/PDF field-code artifacts from broken style references. These
        // sometimes wrap across a line break ("...style in\ndocument."), and
        // often repeat 2-4 times in a row at a page boundary.
        $text = preg_replace('/Error!\s*\n?\s*No text of specified style in\s*\n?\s*document\.?/iu', ' ', $text) ?? $text;

        // Running page header: a short title line immediately followed by an
        // "Operation Guide(for ...)" line — this repeats at every page break
        // and often lands mid-procedure (e.g. between two numbered steps).
        $text = preg_replace('/^.{2,60}\n\s*Operation Guide\(for [^\n)]+\)\s*$/mu', ' ', $text) ?? $text;

        // Alternate running-header variant: the section's own numbered
        // heading (e.g. "3.1.2.1.2.3.4 Installing Ubuntu OS (ARM)") repeats
        // verbatim mid-section at a page break, immediately followed by a
        // lone page number — with none of the "Operation Guide(for ...)"
        // marker the pattern above looks for. Left unstripped, this both
        // pollutes the chunk with a stray number line AND makes the same
        // heading appear twice if that chunk later gets merged with the
        // section's opening chunk in a reply.
        $text = preg_replace(
            '/^(\d{1,2}(?:\.\d{1,3}){1,6}\s+[A-Z][A-Za-z0-9À-ÖØ-öø-ÿ ,\-\(\)\/&\']{2,100})\n\s*\d{1,4}\s*\n/mu',
            ' ',
            $text
        ) ?? $text;

        // Running page footer: "Issue 01 (2024-10-30)" + a copyright/company
        // line + a lone page number, repeated at the bottom of every page.
        $text = preg_replace(
            '/Issue\s+\d+\s*\(\d{4}-\d{2}-\d{2}\)\s*\n+\s*(?:Copyright[^\n]*|[A-Za-z0-9 .,]+,\s*[A-Za-z0-9 .,]+)\s*\n+\s*[ivxlcdm0-9]{1,6}\s*\n/imu',
            "\n",
            $text
        ) ?? $text;

        // Table-of-contents block: dot-leader lines like "Cloning Template
        // Cross-Site ..... 445" carry no real content. Long titles sometimes
        // wrap across two lines, with the dot-leader landing only on the
        // second — stripping single matching lines would leave the wrapped
        // title fragment behind to be misdetected as a real heading, so this
        // removes the whole contiguous ToC block (tolerating a short gap of
        // non-dot-leader lines for wrapped titles/blank lines in between).
        $text = $this->stripTableOfContentsBlock($text);

        // Safety net for any stray single-line dot-leader entry outside a
        // dense-enough block to be caught above.
        $text = preg_replace('/^.*\.{4,}\s*\d+\s*$/mu', '', $text) ?? $text;

        // Collapse horizontal whitespace only, keep line breaks intact.
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/ *\n */', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * Removes the whole table-of-contents block by finding contiguous runs
     * of dot-leader lines (tolerating short gaps between them, since a
     * wrapped ToC title puts its dot-leader on the line after the title
     * text). Ordinary body text never has this density of dot-leader lines,
     * so this only ever fires inside an actual contents/index listing.
     */
    private function stripTableOfContentsBlock(string $text): string
    {
        $lines = explode("\n", $text);
        $count = count($lines);

        $isTocLine = [];
        foreach ($lines as $i => $line) {
            $isTocLine[$i] = preg_match('/\.{4,}\s*\d+\s*$/u', $line) === 1;
        }

        $keep = array_fill(0, $count, true);
        $i = 0;

        while ($i < $count) {
            if (!$isTocLine[$i]) {
                $i++;
                continue;
            }

            $lastToc = $i;
            $j = $i + 1;

            while ($j < $count) {
                if ($isTocLine[$j]) {
                    $lastToc = $j;
                    $j++;
                    continue;
                }

                $gap = 0;
                while ($j < $count && !$isTocLine[$j] && $gap < 4) {
                    $gap++;
                    $j++;
                }

                if ($j < $count && $isTocLine[$j]) {
                    continue;
                }

                break;
            }

            for ($k = $i; $k <= $lastToc; $k++) {
                $keep[$k] = false;
            }

            $i = $lastToc + 1;
        }

        $result = [];
        foreach ($lines as $i => $line) {
            if ($keep[$i]) {
                $result[] = $line;
            }
        }

        return implode("\n", $result);
    }

    /**
     * Splits the document into one chunk per section/sub-section wherever
     * possible, instead of an arbitrary fixed character count. Falls back to
     * the old fixed-size sentence chunking if the document doesn't look
     * structured enough (too few detected headings) to trust a section split.
     */
    private function splitIntoChunks(string $text): array
    {
        $sections = $this->splitBySectionHeadings($text);

        if (count($sections) < self::MIN_HEADINGS_TO_USE_SECTION_SPLIT) {
            return $this->splitByFixedSize($text);
        }

        $chunks = [];

        foreach ($sections as $section) {
            $section = trim($section);

            if ($section === '') {
                continue;
            }

            if ($this->sectionBodyLength($section) < self::MIN_SECTION_BODY_LENGTH) {
                // Heading-only entry (chapter mini-outline duplicate) — the
                // real section with this same heading and actual content
                // follows shortly after, so this one is safe to drop.
                continue;
            }

            if (mb_strlen($section) <= self::MAX_CHUNK_LENGTH) {
                $chunks[] = $section;
                continue;
            }

            // A single section is too long on its own (e.g. a long numbered
            // procedure) — split it further, but keep its heading attached
            // to every resulting part so each piece is still self-describing.
            foreach ($this->splitLongSection($section) as $part) {
                $chunks[] = $part;
            }
        }

        return $chunks !== [] ? $chunks : $this->splitByFixedSize($text);
    }

    /**
     * Length of a section's body, excluding its own heading line.
     */
    private function sectionBodyLength(string $section): int
    {
        $lines = preg_split('/\n/', $section, 2) ?: [$section];
        $body = trim($lines[1] ?? '');

        return mb_strlen($body);
    }

    /**
     * Detects numbered technical-manual headings — e.g. "3.1.4.1.5 Cloning
     * Template Cross-Site" — sitting alone on their own line, and splits the
     * document at each one. Each returned piece starts with its heading and
     * runs until (but not including) the next heading, so it's the full
     * content of that section/sub-section.
     */
    private function splitBySectionHeadings(string $text): array
    {
        $lines = preg_split('/\n/', $text) ?: [];

        $headingPattern = '/^(\d{1,2}(?:\.\d{1,3}){1,6})\s+([A-Z][A-Za-z0-9À-ÖØ-öø-ÿ ,\-\(\)\/&\']{2,100})$/u';

        $sections = [];
        $current = [];
        $headingCount = 0;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($trimmed !== '' && preg_match($headingPattern, $trimmed) === 1) {
                if ($current !== []) {
                    $sections[] = implode("\n", $current);
                }

                $current = [$trimmed];
                $headingCount++;
                continue;
            }

            $current[] = $line;
        }

        if ($current !== []) {
            $sections[] = implode("\n", $current);
        }

        // Not enough real headings found — signal the caller to fall back
        // rather than pretending this document is neatly structured.
        if ($headingCount < self::MIN_HEADINGS_TO_USE_SECTION_SPLIT) {
            return [];
        }

        return $sections;
    }

    /**
     * Splits one oversized section into multiple parts on sentence
     * boundaries, re-prefixing each part with the section's own heading line
     * so every chunk is still self-describing on its own.
     */
    private function splitLongSection(string $section): array
    {
        $lines = preg_split('/\n/', $section, 2) ?: [$section];
        $heading = trim($lines[0] ?? '');
        $body = trim($lines[1] ?? '');

        if ($body === '') {
            return [mb_substr($section, 0, self::MAX_CHUNK_LENGTH)];
        }

        $sentences = preg_split('/(?<=[.?!])\s+/', $body) ?: [$body];

        $parts = [];
        $currentBody = '';

        foreach ($sentences as $sentence) {
            $candidate = trim($currentBody . ' ' . $sentence);

            if ($currentBody !== '' && mb_strlen($heading . "\n" . $candidate) > self::MAX_CHUNK_LENGTH) {
                $parts[] = trim($heading . "\n" . $currentBody);
                $currentBody = $sentence;
                continue;
            }

            $currentBody = $candidate;
        }

        if (trim($currentBody) !== '') {
            $parts[] = trim($heading . "\n" . $currentBody);
        }

        return $parts !== [] ? $parts : [mb_substr($section, 0, self::MAX_CHUNK_LENGTH)];
    }

    /**
     * The old behavior — fixed ~1200-char, sentence-boundary chunking — kept
     * as a fallback for documents without consistent numbered headings
     * (plain text files, differently-formatted PDFs, etc).
     */
    private function splitByFixedSize(string $text): array
    {
        $flatText = preg_replace('/\s+/', ' ', $text) ?? $text;

        $sentences = preg_split(
            '/(?<=[.?!])\s+/',
            $flatText
        );

        $chunks = [];
        $current = '';

        foreach ($sentences as $sentence) {
            if (strlen($current . ' ' . $sentence) > 1200) {
                $chunks[] = trim($current);
                $current = $sentence;
            } else {
                $current .= ' ' . $sentence;
            }
        }

        if (!empty(trim($current))) {
            $chunks[] = trim($current);
        }

        return $chunks;
    }

    public function getAll()
    {
        return $this->documentRepository->getAll();
    }

    public function findById(int $id): ?Document
    {
        return $this->documentRepository->findById($id);
    }

    public function delete(Document $document): bool
    {
        return DB::transaction(function () use ($document) {

            KnowledgeChunk::where(
                'document_id',
                $document->id
            )->delete();

            if (
                $document->file_path &&
                Storage::disk('public')->exists($document->file_path)
            ) {

                Storage::disk('public')->delete(
                    $document->file_path
                );

            }

            return $this->documentRepository->delete($document);
        });
    }
}