<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use App\Models\SupportTicketReply;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SupportTicketController extends Controller
{
    /**
     * List all tickets for the authenticated shop owner.
     */
    public function index(Request $request, $shopId)
    {
        $tickets = SupportTicket::where('shop_id', $shopId)
            ->with(['submittedBy:id,name,email', 'replies'])
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $tickets,
        ]);
    }

    /**
     * Create a new support ticket.
     */
    public function store(Request $request, $shopId)
    {
        $validated = $request->validate([
            'subject'     => 'required|string|max:255',
            'message'     => 'required|string',
            'type'        => 'required|in:problem,update_request,general,billing',
            'priority'    => 'required|in:low,medium,high,urgent',
            'attachments' => 'nullable|array',
        ]);

        $ticket = SupportTicket::create([
            ...$validated,
            'shop_id' => $shopId,
            'user_id' => Auth::id(),
            'status'  => 'open',
        ]);

        $ticket->load(['submittedBy:id,name,email', 'replies']);

        return response()->json([
            'success' => true,
            'data'    => $ticket,
        ], 201);
    }

    /**
     * Show a single ticket with all replies.
     */
    public function show($shopId, $ticketId)
    {
        $ticket = SupportTicket::where('shop_id', $shopId)
            ->with(['submittedBy:id,name,email', 'replies.user:id,name,email', 'assignedTo:id,name'])
            ->findOrFail($ticketId);

        return response()->json([
            'success' => true,
            'data'    => $ticket,
        ]);
    }

    /**
     * Post a reply to a ticket (shop owner side).
     */
    public function reply(Request $request, $shopId, $ticketId)
    {
        $ticket = SupportTicket::where('shop_id', $shopId)->findOrFail($ticketId);

        $validated = $request->validate([
            'message'     => 'required|string',
            'attachments' => 'nullable|array',
        ]);

        $reply = SupportTicketReply::create([
            'ticket_id'      => $ticket->id,
            'user_id'        => Auth::id(),
            'message'        => $validated['message'],
            'attachments'    => $validated['attachments'] ?? null,
            'is_admin_reply' => false,
        ]);

        // Re-open if it was resolved, so admin sees the follow-up
        if ($ticket->status === 'resolved' || $ticket->status === 'closed') {
            $ticket->update(['status' => 'open']);
        }

        $reply->load('user:id,name,email');

        return response()->json([
            'success' => true,
            'data'    => $reply,
        ], 201);
    }

    /**
     * Close a ticket from shop owner side.
     */
    public function close($shopId, $ticketId)
    {
        $ticket = SupportTicket::where('shop_id', $shopId)->findOrFail($ticketId);
        $ticket->update(['status' => 'closed']);

        return response()->json([
            'success' => true,
            'message' => 'Ticket closed.',
        ]);
    }
}
