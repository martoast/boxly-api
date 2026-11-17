<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Team emails (2026-11-17): an admin (or the admin's CLI token — how Boxly AI writes to the team) sends a plain-text email to
 * Boxly STAFF only — admins and employees. A customer address is refused: customers are written to through campaigns,
 * quotes and the other customer emails, never through this.
 */
class AdminTeamEmailController extends Controller
{
    private const TEAM_INBOX = 'contact@boxly.mx';

    /** Boxly staff who can receive a team email: id, name, email, role, team. */
    public function index()
    {
        $staff = User::query()
            ->whereIn('role', [User::ROLE_ADMIN, User::ROLE_EMPLOYEE])
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role', 'team']);

        return response()->json(['success' => true, 'data' => $staff]);
    }

    /**
     * Send a plain-text email to one or more staff members. Body: to (array of staff emails or user ids), subject, body,
     * optional cc_me (true = a copy to contact@boxly.mx). Replies go to contact@boxly.mx. 422 if any recipient is not a staff account.
     */
    public function send(Request $request)
    {
        $validated = $request->validate([
            'to'      => 'required|array|min:1|max:10',
            'to.*'    => 'required',
            'subject' => 'required|string|max:200',
            'body'    => 'required|string|max:20000',
            'cc_me'   => 'sometimes|boolean',
        ]);

        $recipients = [];
        $notStaff = [];
        foreach ($validated['to'] as $who) {
            $user = is_numeric($who)
                ? User::find((int) $who)
                : User::where('email', strtolower(trim((string) $who)))->first();
            if (! $user || ! in_array($user->role, [User::ROLE_ADMIN, User::ROLE_EMPLOYEE], true)) {
                $notStaff[] = (string) $who;
                continue;
            }
            $recipients[$user->id] = $user;
        }
        if ($notStaff) {
            return response()->json([
                'success' => false,
                'message' => 'Team emails go only to Boxly staff (admins and employees).',
                'errors'  => ['to' => $notStaff],
            ], 422);
        }

        $sender = $request->user();
        $to = array_values(array_map(fn ($u) => $u->email, $recipients));
        // replies go to Boxly's own inbox (the Mailgun sender), not the sender's account — the admin CLI token's account is not a
        // real inbox (Alex 2026-11-17); cc_me copies that inbox too
        Mail::raw($validated['body'], function ($m) use ($to, $validated) {
            $m->to($to)->subject($validated['subject'])->replyTo(self::TEAM_INBOX, 'Boxly');
            if (! empty($validated['cc_me'])) {
                $m->cc(self::TEAM_INBOX);
            }
        });
        Log::info('team email sent', ['by' => $sender->id, 'to' => $to, 'subject' => $validated['subject']]);

        return response()->json(['success' => true, 'data' => ['to' => $to, 'subject' => $validated['subject']]]);
    }
}
