<?php

namespace App\Http\Controllers;

use App\Http\Requests\SubmissionRequest;
use App\Models\Submission;
use App\Services\DeliverSubmission;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;

class SubmissionController extends Controller
{
    public function store(SubmissionRequest $request, DeliverSubmission $delivery): RedirectResponse
    {
        $career = $request->routeIs('careers.submit');
        $data = $request->safe()->except(['consent', 'website', 'resume']);
        $data['type'] = $career ? 'application' : 'enquiry';
        $data['locale'] = app()->getLocale();
        if ($career) {
            $file = $request->file('resume');
            $data['resume_path'] = $file->store('resumes', 'local');
            $data['resume_extension'] = strtolower($file->getClientOriginalExtension());
        }
        try {
            $submission = Submission::create($data);
        } catch (QueryException $exception) {
            if (isset($data['resume_path'])) {
                Storage::disk('local')->delete($data['resume_path']);
            }
            throw $exception;
        }
        $delivered = $delivery->deliver($submission);

        return to_route($career ? 'careers' : 'contact', ['locale' => app()->getLocale()])
            ->with('status', __('site.'.($delivered ? 'submissionDelivered' : 'submissionPending')));
    }
}
