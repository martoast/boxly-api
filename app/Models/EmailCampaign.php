<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmailCampaign extends Model
{
    use HasFactory;

    const STATUS_DRAFT = 'draft';
    const STATUS_SENDING = 'sending';
    const STATUS_PAUSED = 'paused';
    const STATUS_COMPLETED = 'completed';
    const STATUS_CANCELLED = 'cancelled';

    const AUDIENCE_ALL = 'all';
    const AUDIENCE_HAS_ORDERS = 'has_orders';
    const AUDIENCE_NO_ORDERS = 'no_orders';
    const AUDIENCE_ACTIVE_ORDERS = 'active_orders';
    const AUDIENCE_EXPAT = 'expat';
    const AUDIENCE_BUSINESS = 'business';
    const AUDIENCE_SHOPPER = 'shopper';

    protected $fillable = [
        'created_by',
        'name',
        'subject',
        'body',
        'link_url',
        'link_text',
        'audience',
        'status',
        'batch_size',
        'batch_delay_seconds',
        'total_recipients',
        'sent_count',
        'opened_count',
        'clicked_count',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'batch_size' => 'integer',
            'batch_delay_seconds' => 'integer',
            'total_recipients' => 'integer',
            'sent_count' => 'integer',
            'opened_count' => 'integer',
            'clicked_count' => 'integer',
        ];
    }

    protected $appends = ['open_rate', 'click_rate'];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function recipients()
    {
        return $this->hasMany(CampaignRecipient::class, 'campaign_id');
    }

    public function getOpenRateAttribute(): float
    {
        if (!$this->sent_count) return 0;
        return round(($this->opened_count / $this->sent_count) * 100, 1);
    }

    public function getClickRateAttribute(): float
    {
        if (!$this->sent_count) return 0;
        return round(($this->clicked_count / $this->sent_count) * 100, 1);
    }
}
