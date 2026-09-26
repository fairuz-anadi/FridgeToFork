<?php

namespace App\Http\Controllers;

use App\Mail\ContactSubmissionMail;
use App\Models\ContactSubmission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Throwable;

class ContactController extends Controller
{
    /** File a complaint; it lands in the admin inbox and the admin's email. */
    public function store(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'category' => ['required', Rule::in(ContactSubmission::CATEGORIES)],
            'message' => 'required|string|min:10|max:5000',
        ]);

        $submission = ContactSubmission::create($validated + [
            'user_id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
        ]);

        $this->notifyAdmin($submission);

        return response()->json([
            'message' => 'Your message has been sent to the admin.',
            'data' => $submission,
        ], 201);
    }

    /** The signed-in cook's own messages, with any reply from the admin. */
    public function mine(Request $request)
    {
        return response()->json([
            'data' => $request->user()->contactSubmissions()->latest()->get(),
        ]);
    }

    private function notifyAdmin(ContactSubmission $submission): void
    {
        $to = config('mail.complaints_to');
        if (!$to) {
            return;
        }

        // The complaint is already saved; a mail outage must not lose it.
        try {
            Mail::to($to)->send(new ContactSubmissionMail($submission));
        } catch (Throwable $e) {
            Log::warning('Complaint email could not be sent: ' . $e->getMessage());
        }
    }
}
