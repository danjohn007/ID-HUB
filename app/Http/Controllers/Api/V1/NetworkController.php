<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\AlertResource;
use App\Http\Resources\DeviceResource;
use App\Http\Resources\RepeaterResource;
use App\Models\Alert;
use App\Models\NetworkDevice;
use App\Models\Repeater;
use App\Services\MetricsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Red (repetidores y equipos con estado), alertas y KPIs principales.
 */
class NetworkController extends ApiController
{
    public function repeaters(Request $request)
    {
        $q = Repeater::query()->withLoad()->with('zone')
            ->when($request->query('status'), fn ($q, $v) => $q->whereIn('status', explode(',', $v)))
            ->when($request->query('zone_id'), fn ($q, $v) => $q->where('zone_id', $v))
            ->when($request->query('search'), fn ($q, $t) => $q->where(fn ($w) => $w->where('code', 'like', "%$t%")->orWhere('name', 'like', "%$t%")))
            ->orderBy('code');

        return RepeaterResource::collection($q->paginate($this->perPage($request, 50)));
    }

    public function repeater(int $id): JsonResponse
    {
        $repeater = Repeater::query()->withLoad()->with('zone')->findOrFail($id);
        $devices = NetworkDevice::query()->where('repeater_id', $repeater->id)->whereNull('client_id')->orderBy('type')->get();

        return $this->ok([
            'data' => new RepeaterResource($repeater),
            'devices' => DeviceResource::collection($devices),
        ]);
    }

    public function devices(Request $request)
    {
        $q = NetworkDevice::query()
            ->when($request->query('type'), fn ($q, $v) => $q->whereIn('type', explode(',', $v)))
            ->when($request->query('status'), fn ($q, $v) => $q->whereIn('status', explode(',', $v)))
            ->when($request->query('repeater_id'), fn ($q, $v) => $q->where('repeater_id', $v))
            ->when($request->query('client_id'), fn ($q, $v) => $q->where('client_id', $v))
            ->when($request->query('search'), fn ($q, $t) => $q->where(fn ($w) => $w->where('name', 'like', "%$t%")->orWhere('ip', 'like', "%$t%")->orWhere('mac', 'like', "%$t%")));
        $this->applySort($q, $request, ['id', 'name', 'status', 'last_seen_at', 'latency_ms'], 'name');

        return DeviceResource::collection($q->paginate($this->perPage($request, 50)));
    }

    public function device(int $id): DeviceResource
    {
        return new DeviceResource(NetworkDevice::query()->findOrFail($id));
    }

    public function alerts(Request $request)
    {
        $q = Alert::query()
            ->when($request->query('status', 'open') === 'open', fn ($q) => $q->open())
            ->when(! in_array($request->query('status', 'open'), ['open', 'all'], true), fn ($q) => $q->where('status', $request->query('status')))
            ->when($request->query('severity'), fn ($q, $v) => $q->whereIn('severity', explode(',', $v)))
            ->when($request->query('type'), fn ($q, $v) => $q->where('type', $v))
            ->latest('id');

        return AlertResource::collection($q->paginate($this->perPage($request)));
    }

    /** KPIs del panel principal (sección 52). */
    public function metrics(): JsonResponse
    {
        $h = app(MetricsService::class)->headline();
        if (! $this->allows('finance.view')) {
            foreach (['mrr', 'avg_ticket', 'collected'] as $k) {
                unset($h[$k]);
            }
        }

        return $this->ok([
            'data' => $h,
            'currency' => tenant()->currency,
            'generated_at' => now()->toIso8601String(),
        ]);
    }
}
