<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use App\Models\SupportTicketReply;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SupportTicketAdminController extends Controller
{
    /**
     * List all tickets across all shops (admin view).
     */
    public function index(Request $request)
    {
        $query = SupportTicket::with([
            'shop:id,name,slug',
            'submittedBy:id,name,email',
            'assignedTo:id,name',
        ])->orderByDesc('created_at');

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by priority
        if ($request->has('priority')) {
            $query->where('priority', $request->priority);
        }

        return response()->json([
            'success' => true,
            'data'    => $query->get(),
        ]);
    }

    /**
     * Show a single ticket with all replies.
     */
    public function show($ticketId)
    {
        $ticket = SupportTicket::with([
            'shop:id,name,slug',
            'submittedBy:id,name,email',
            'replies.user:id,name,email',
            'assignedTo:id,name',
        ])->findOrFail($ticketId);

        return response()->json([
            'success' => true,
            'data'    => $ticket,
        ]);
    }

    /**
     * Admin replies to a ticket.
     */
    public function reply(Request $request, $ticketId)
    {
        $ticket = SupportTicket::findOrFail($ticketId);

        $validated = $request->validate([
            'message'     => 'required|string',
            'attachments' => 'nullable|array',
        ]);

        $reply = SupportTicketReply::create([
            'ticket_id'      => $ticket->id,
            'user_id'        => Auth::id(),
            'message'        => $validated['message'],
            'attachments'    => $validated['attachments'] ?? null,
            'is_admin_reply' => true,
        ]);

        // Automatically move ticket to in_progress when admin first replies
        if ($ticket->status === 'open') {
            $ticket->update([
                'status'      => 'in_progress',
                'assigned_to' => Auth::id(),
            ]);
        }

        $reply->load('user:id,name,email');

        return response()->json([
            'success' => true,
            'data'    => $reply,
        ], 201);
    }

    /**
     * Update ticket status (admin only).
     */
    public function updateStatus(Request $request, $ticketId)
    {
        $ticket = SupportTicket::findOrFail($ticketId);

        $validated = $request->validate([
            'status' => 'required|in:open,in_progress,resolved,closed',
        ]);

        $updates = ['status' => $validated['status']];

        if ($validated['status'] === 'resolved') {
            $updates['resolved_at'] = now();
        }

        $ticket->update($updates);

        return response()->json([
            'success' => true,
            'data'    => $ticket->fresh(),
        ]);
    }
}
