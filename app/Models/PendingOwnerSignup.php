<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PendingOwnerSignup extends Model
{
    /** How long the emailed link works. */
    public const LINK_HOURS = 24;

    /** Seconds before the confirmation email may be sent again. */
    public const RESEND_SECONDS = 60;

    /** The version of the Terms of Service the owner agreed to (the "Last updated" date). */
    public const TERMS_VERSION = '2026-11-02';

    protected $fillable = [
        'email', 'password', 'token_hash', 'poll_token', 'terms_accepted_at', 'terms_version',
        'expires_at', 'last_sent_at', 'verified_at', 'user_id',
    ];

    protected $hidden = ['password', 'token_hash', 'poll_token'];

    protected $casts = [
        'terms_accepted_at' => 'datetime',
        'expires_at' => 'datetime',
        'last_sent_at' => 'datetime',
        'verified_at' => 'datetime',
    ];
}
