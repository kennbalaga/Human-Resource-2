<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The file behind a leave attachment kept on the `database` disk.
 * See LeaveAttachmentStorage.
 */
class LeaveAttachmentContent extends Model
{
    protected $primaryKey = 'leave_attachment_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'leave_attachment_id',
        'contents',
    ];
}
