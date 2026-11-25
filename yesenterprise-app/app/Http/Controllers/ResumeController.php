<?php

namespace App\Http\Controllers;

use App\Models\Submission;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ResumeController extends Controller
{
    public function __invoke(Submission $submission): StreamedResponse
    {
        abort_unless(auth()->user()?->is_admin, 403);
        abort_unless($submission->resume_path && Storage::disk('local')->exists($submission->resume_path), 404);

        return Storage::disk('local')->download(
            $submission->resume_path,
            'application-'.$submission->id.'.'.$submission->resume_extension,
            ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'],
        );
    }
}
