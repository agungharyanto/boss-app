<?php

namespace App\Services\Installation;

use App\Enums\WorkOrderPhotoType;
use App\Models\WorkOrder;
use App\Models\WorkOrderPhoto;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class WorkOrderPhotoService
{
    /**
     * One photo per type per work order — a re-upload of an already-present
     * type replaces the stored file (old one deleted first) and the
     * whatsapp_message_templates-style updateOrCreate keeps a single row,
     * matching the DB unique(work_order_id, type) constraint.
     *
     * Stored on the 'local' disk (private, never publicly served) — its
     * actual root is storage/app/private/ on Laravel 12 (not bare
     * storage/app/), a framework default unrelated to this feature.
     */
    public function store(WorkOrder $workOrder, WorkOrderPhotoType $type, UploadedFile $file): WorkOrderPhoto
    {
        $existing = WorkOrderPhoto::where('work_order_id', $workOrder->id)
            ->where('type', $type->value)
            ->first();

        if ($existing !== null) {
            Storage::disk('local')->delete($existing->file_path);
        }

        $extension = $file->getClientOriginalExtension() ?: $file->extension();

        $storedPath = Storage::disk('local')->putFileAs(
            "work-order-photos/{$workOrder->id}",
            $file,
            "{$type->value}.{$extension}"
        );

        return WorkOrderPhoto::updateOrCreate(
            ['work_order_id' => $workOrder->id, 'type' => $type->value],
            ['file_path' => $storedPath, 'uploaded_at' => now()]
        );
    }
}
