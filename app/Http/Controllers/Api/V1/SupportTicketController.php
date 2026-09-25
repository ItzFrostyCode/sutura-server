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
     * List all tickets for the authenticated store owner.
     */
    public function index(Request $request, $storeId)
    {
        $tickets = SupportTicket::where('store_id', $storeId)
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
    public function store(Request $request, $storeId)
    {
        $validated = $request->validate([
            'subject' => 'required|string|max:255',
            'message' => 'required|string',
            'type' => 'required|in:problem,update_request,general,billing',
            'priority' => 'required|in:low,medium,high,urgent',
            'attachments' => 'nullable|array',
        ]);

        $ticket = SupportTicket::create([
            ...$validated,
            'store_id' => $storeId,
            'user_id' => Auth::id(),
            'status' => 'open',
        ]);

        $ticket->load(['submittedBy:id,name,email', 'replies']);

        return response()->json([
            'success' => true,
            'data' => $ticket,
        ], 201);
    }

    /**
     * Show a single ticket with all replies.
     */
    public function show($storeId, $ticketId)
    {
        $ticket = SupportTicket::where('store_id', $storeId)
            ->with(['submittedBy:id,name,email', 'replies.user:id,name,email', 'assignedTo:id,name'])
            ->findOrFail($ticketId);

        return response()->json([
            'success' => true,
            'data' => $ticket,
        ]);
    }

    /**
     * Post a reply to a ticket (store owner side).
     */
    public function reply(Request $request, $storeId, $ticketId)
    {
        $ticket = SupportTicket::where('store_id', $storeId)->findOrFail($ticketId);

        $validated = $request->validate([
            'message' => 'required|string',
            'attachments' => 'nullable|array',
        ]);

        $reply = SupportTicketReply::create([
            'ticket_id' => $ticket->id,
            'user_id' => Auth::id(),
            'message' => $validated['message'],
            'attachments' => $validated['attachments'] ?? null,
            'is_admin_reply' => false,
        ]);

        // Re-open if it was resolved, so admin sees the follow-up
        if ($ticket->status === 'resolved' || $ticket->status === 'closed') {
            $ticket->update(['status' => 'open']);
        }

        $reply->load('user:id,name,email');

        return response()->json([
            'success' => true,
            'data' => $reply,
        ], 201);
    }

    /**
     * Close a ticket from store owner side.
     */
    public function close($storeId, $ticketId)
    {
        $ticket = SupportTicket::where('store_id', $storeId)->findOrFail($ticketId);
        $ticket->update(['status' => 'closed']);

        return response()->json([
            'success' => true,
            'message' => 'Ticket closed.',
        ]);
    }

    /**
     * Cross-store "My Support Tickets" for whoever is logged in — the
     * customer-facing counterpart to index() above, which is store-scoped
     * and store-owner only. Same no-role-gate, filter-by-own-id pattern as
     * /my-orders/my-appointments. Currently the only way a customer gets a
     * row here is via CatalogInteractionController::report() ("Report This
     * Product") — there's no general "file a ticket" form yet, so this is
     * a view(+reply) list, not a ticket-creation surface.
     */
    public function myTickets(Request $request)
    {
        $tickets = SupportTicket::where('user_id', $request->user()->id)
            ->with(['store:id,name,slug,logo_path', 'replies.user:id,name'])
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['success' => true, 'data' => $tickets]);
    }

    public function myTicketShow(Request $request, $ticketId)
    {
        $ticket = SupportTicket::where('user_id', $request->user()->id)
            ->with(['store:id,name,slug,logo_path', 'replies.user:id,name'])
            ->findOrFail($ticketId);

        return response()->json(['success' => true, 'data' => $ticket]);
    }

    /**
     * Customer reply on their own ticket — mirrors reply() above but scoped
     * to the caller's own tickets instead of a store's, and always tagged
     * is_admin_reply=false since only System Admin's own reply endpoint can
     * set that true.
     */
    public function myTicketReply(Request $request, $ticketId)
    {
        $ticket = SupportTicket::where('user_id', $request->user()->id)->findOrFail($ticketId);

        $validated = $request->validate([
            'message' => 'required|string',
        ]);

        $reply = SupportTicketReply::create([
            'ticket_id' => $ticket->id,
            'user_id' => $request->user()->id,
            'message' => $validated['message'],
            'is_admin_reply' => false,
        ]);

        if (in_array($ticket->status, ['resolved', 'closed'], true)) {
            $ticket->update(['status' => 'open']);
        }

        $reply->load('user:id,name,email');

        return response()->json(['success' => true, 'data' => $reply], 201);
    }
}
