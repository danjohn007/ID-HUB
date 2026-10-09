<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\ChargeResource;
use App\Http\Resources\PaymentResource;
use App\Http\Resources\PlanResource;
use App\Http\Resources\PromotionResource;
use App\Http\Resources\ReferralResource;
use App\Http\Resources\TicketCommentResource;
use App\Http\Resources\TicketResource;
use App\Models\Client;
use App\Models\Plan;
use App\Models\Promotion;
use App\Models\Referral;
use App\Models\Ticket;
use App\Services\BillingService;
use App\Services\ReferralService;
use App\Services\TicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;

/**
 * App del cliente final (sección 33). Todo se limita al cliente dueño del token.
 */
class ClientAppController extends ApiController
{
    /** Categorías que el cliente puede reportar. */
    protected const REPORT_CATEGORIES = ['sin_internet', 'lento', 'antena', 'router', 'pago', 'domicilio', 'plan', 'otro'];

    /** Inicio: "Hola, Juan · Internet ACTIVO · Plan Hogar 30 Mbps · Próximo pago $450 · 10 octubre". */
    public function home(): JsonResponse
    {
        $client = $this->appClient()->load('plan');
        $next = $this->nextPayment($client);
        $status = config("wisp.client_status.{$client->service_status}", [$client->service_status, 'slate']);
        $statusLabel = mb_strtoupper($status[0]);

        $headline = collect([
            'Hola, '.$client->first_name,
            'Internet '.$statusLabel,
            $client->plan ? 'Plan '.$client->plan->name.($this->speedInName($client->plan) ? '' : ' '.$client->plan->speedLabel()) : null,
            $next ? 'Próximo pago '.money($next['amount'], false) : null,
            $next['date_label'] ?? null,
        ])->filter()->implode(' · ');

        return $this->ok([
            'greeting' => 'Hola, '.$client->first_name,
            'headline' => $headline,
            'client' => ['id' => $client->id, 'code' => $client->code, 'name' => $client->full_name],
            'service' => [
                'status' => $client->service_status,
                'label' => $statusLabel,
                'color' => $status[1] ?? null,
                'online' => (bool) $client->online,
                'active' => in_array($client->service_status, ['activo', 'moroso'], true),
            ],
            'plan' => $client->plan ? [
                'name' => $client->plan->name,
                'speed' => $client->plan->speedLabel(),
                'download_mbps' => (float) $client->plan->download_mbps,
                'upload_mbps' => (float) $client->plan->upload_mbps,
            ] : null,
            'next_payment' => $next,
            'balance' => round((float) $client->balance, 2),
            'credit_balance' => round((float) $client->credit_balance, 2),
            'open_tickets' => Ticket::query()->where('client_id', $client->id)->open()->count(),
            'promotions' => $this->promotionsFor($client)->count(),
            'tenant' => AuthController::tenantInfo(tenant()),
        ]);
    }

    public function status(): JsonResponse
    {
        $client = $this->appClient();
        $billing = app(BillingService::class);
        $status = config("wisp.client_status.{$client->service_status}", [$client->service_status, 'slate']);

        return $this->ok([
            'service_status' => ['value' => $client->service_status, 'label' => $status[0], 'color' => $status[1] ?? null],
            'online' => (bool) $client->online,
            'last_online_at' => $client->last_online_at?->toIso8601String(),
            'signal_dbm' => $client->signal_dbm !== null ? (float) $client->signal_dbm : null,
            'suspended_at' => $client->suspended_at?->toIso8601String(),
            'balance' => round((float) $client->balance, 2),
            'overdue_amount' => round($client->overdueAmount(), 2),
            'overdue_days' => $billing->overdueDays($client),
            'next_payment' => $this->nextPayment($client),
            'message' => match ($client->service_status) {
                'suspendido' => 'Tu servicio está suspendido. Realiza tu pago para reactivarlo.',
                'moroso' => 'Tienes un pago vencido. Evita la suspensión pagando hoy.',
                'activo' => 'Tu servicio está activo.',
                default => $status[0],
            },
        ]);
    }

    public function plan(): JsonResponse
    {
        $client = $this->appClient()->load('plan');
        $upgrades = Plan::query()->where('active', true)->where('public', true)
            ->when($client->plan, fn ($q) => $q->where('download_mbps', '>', $client->plan->download_mbps))
            ->orderBy('price')->limit(5)->get();

        return $this->ok([
            'plan' => $client->plan ? new PlanResource($client->plan) : null,
            'billing_day' => (int) $client->billing_day,
            'upgrades' => PlanResource::collection($upgrades),
        ]);
    }

    public function usage(): JsonResponse
    {
        $client = $this->appClient()->load('plan');
        $cap = $client->plan?->data_cap_gb;
        $used = (float) $client->month_usage_gb;

        return $this->ok([
            'period' => ['from' => now()->startOfMonth()->toDateString(), 'to' => now()->toDateString(), 'label' => ucfirst(now()->translatedFormat('F Y'))],
            'month_usage_gb' => round($used, 2),
            'data_cap_gb' => $cap,
            'usage_pct' => $cap ? round(min(100, $used / $cap * 100), 1) : null,
            'unlimited' => ! $cap,
            'online' => (bool) $client->online,
            'signal_dbm' => $client->signal_dbm !== null ? (float) $client->signal_dbm : null,
        ]);
    }

    public function payments(Request $request)
    {
        $q = $this->appClient()->payments()->where('status', 'completed')->latest('paid_at');

        return PaymentResource::collection($q->paginate($this->perPage($request, 20)));
    }

    public function charges(Request $request)
    {
        $q = $this->appClient()->charges()->where('status', '!=', 'cancelled')
            ->when(! $request->boolean('all'), fn ($q) => $q->open())
            ->orderByDesc('due_date');

        return ChargeResource::collection($q->paginate($this->perPage($request, 20)));
    }

    /** Enlace "Pagar ahora" (pasarelas en línea) por el saldo o un monto. */
    public function pay(Request $request): JsonResponse
    {
        $client = $this->appClient();
        $data = $this->check($request, ['amount' => 'nullable|numeric|gt:0|max:1000000']);
        $amount = isset($data['amount']) ? (float) $data['amount'] : (float) $client->balance;
        if ($amount <= 0) {
            return $this->ok(['balance' => 0], 'No tienes saldo pendiente por pagar.', 422);
        }

        $link = app(BillingService::class)->paymentLinkFor($client, $amount);
        $url = $link && Route::has('pay.show') ? $link->url() : null;

        return $this->ok([
            'amount' => round($amount, 2),
            'amount_label' => money($amount),
            'url' => $url,
            'expires_at' => $link?->expires_at?->toIso8601String(),
            'concept' => $link?->concept,
        ], $url ? 'Abre el enlace para completar tu pago.' : 'El pago en línea no está disponible por el momento.', $url ? 200 : 503);
    }

    /** Reportar falla: crea un ticket con source=cliente. */
    public function report(Request $request): JsonResponse
    {
        $client = $this->appClient();
        $data = $this->check($request, [
            'category' => ['required', Rule::in(self::REPORT_CATEGORIES)],
            'subject' => 'nullable|string|max:190',
            'description' => 'required|string|max:3000',
        ]);
        $open = Ticket::query()->where('client_id', $client->id)->open()->where('category', $data['category'])->first();
        if ($open) {
            return $this->ok(['data' => new TicketResource($open)], "Ya tienes un reporte abierto ({$open->folio}). Te avisaremos cuando haya novedades.", 409);
        }

        $ticket = app(TicketService::class)->create([
            'client_id' => $client->id,
            'category' => $data['category'],
            'priority' => $data['category'] === 'sin_internet' ? 'alta' : 'media',
            'subject' => $data['subject'] ?? config("wisp.ticket_categories.{$data['category']}").' (reporte desde app)',
            'description' => $data['description'],
            'source' => 'cliente',
            'created_by' => null,
            'repeater_id' => $client->repeater_id,
        ]);

        return $this->ok(['data' => new TicketResource($ticket)], "Reporte {$ticket->folio} recibido. Te contactaremos pronto.", 201);
    }

    public function tickets(Request $request)
    {
        $q = Ticket::query()->where('client_id', $this->appClient()->id)
            ->when($request->query('status') === 'open', fn ($q) => $q->open())
            ->latest('id');

        return TicketResource::collection($q->paginate($this->perPage($request, 20)));
    }

    public function ticket(int $id): JsonResponse
    {
        $ticket = Ticket::query()->where('client_id', $this->appClient()->id)->with('technician')->findOrFail($id);
        $comments = $ticket->comments()->where('internal', false)->with('user')->get();

        return $this->ok([
            'data' => new TicketResource($ticket),
            'technician' => $ticket->technician?->name,
            'comments' => TicketCommentResource::collection($comments),
        ]);
    }

    public function ticketComment(Request $request, int $id): JsonResponse
    {
        $ticket = Ticket::query()->where('client_id', $this->appClient()->id)->findOrFail($id);
        if (! $ticket->isOpen()) {
            return $this->ok([], 'El reporte ya está cerrado. Crea uno nuevo si el problema continúa.', 422);
        }
        $data = $this->check($request, ['body' => 'required|string|max:2000']);
        $comment = $ticket->addComment('Cliente: '.$data['body'], 'comment', false, ['by' => 'client']);

        return $this->ok(['data' => new TicketCommentResource($comment)], 'Comentario enviado.', 201);
    }

    public function support(): JsonResponse
    {
        $t = tenant();
        $wa = preg_replace('/\D+/', '', (string) ($t->whatsapp ?: $t->phone));
        if ($wa && strlen($wa) === 10) {
            $wa = '52'.$wa;
        }
        $client = $this->appClient();

        return $this->ok([
            'company' => $t->name,
            'phone' => $t->phone,
            'whatsapp' => $t->whatsapp,
            'whatsapp_url' => $wa ? 'https://wa.me/'.$wa.'?text='.rawurlencode("Hola, soy el cliente #{$client->code} ({$client->full_name}).") : null,
            'email' => $t->email,
            'address' => $t->address,
            'hours' => $t->setting('portal.support_hours', 'Lunes a sábado de 9:00 a 19:00'),
        ]);
    }

    public function profile(): JsonResponse
    {
        $c = $this->appClient();

        return $this->ok(['data' => $this->profileData($c)]);
    }

    /** Actualizar datos de contacto y/o contraseña del portal. */
    public function updateProfile(Request $request): JsonResponse
    {
        $client = $this->appClient();
        $data = $this->check($request, [
            'phone' => 'sometimes|nullable|string|max:30',
            'whatsapp' => 'sometimes|nullable|string|max:30',
            'email' => 'sometimes|nullable|email|max:150',
            'references' => 'sometimes|nullable|string|max:190',
            'current_password' => 'required_with:new_password|nullable|string',
            'new_password' => 'sometimes|nullable|string|min:6|max:60|confirmed',
        ], ['current_password' => 'contraseña actual', 'references' => 'referencias del domicilio']);

        if (! empty($data['new_password'])) {
            if (! AuthController::checkClientPassword($client, (string) $data['current_password'])) {
                return $this->ok(['errors' => ['current_password' => ['La contraseña actual no es correcta.']]], 'Los datos enviados no son válidos.', 422);
            }
            $client->forceFill(['portal_password' => Hash::make($data['new_password'])]);
        }
        unset($data['current_password'], $data['new_password'], $data['new_password_confirmation']);
        $client->fill($data)->save();

        return $this->ok(['data' => $this->profileData($client->fresh())], 'Tus datos se actualizaron.');
    }

    public function promotions()
    {
        return PromotionResource::collection($this->promotionsFor($this->appClient()));
    }

    public function referrals(): JsonResponse
    {
        $client = $this->appClient();
        $cfg = tenant()->settingsFor('referrals');
        if (! ($cfg['enabled'] ?? true)) {
            return $this->ok(['enabled' => false], 'El programa de referidos no está disponible.');
        }
        $service = app(ReferralService::class);
        $code = $service->ensureCode($client);
        $referrals = Referral::query()->where('referrer_client_id', $client->id)->with('referred')->latest('id')->get();
        $reward = match ($cfg['reward_type'] ?? null) {
            'mes_gratis' => 'un mes gratis',
            default => money((float) ($cfg['reward_value'] ?? 0)).' de saldo a favor',
        };

        return $this->ok([
            'enabled' => true,
            'code' => $code,
            'share_url' => $service->shareUrl($client),
            'share_text' => "¡Contrata Internet con ".tenant()->name." usando mi código {$code}! ".$service->shareUrl($client),
            'reward' => ['type' => $cfg['reward_type'] ?? null, 'value' => (float) ($cfg['reward_value'] ?? 0), 'label' => 'Gana '.$reward.' por cada amigo que contrate y pague su primer mes.'],
            'stats' => [
                'total' => $referrals->count(),
                'rewarded' => $referrals->where('status', 'recompensado')->count(),
                'pending' => $referrals->where('status', 'pendiente')->count(),
            ],
            'referrals' => ReferralResource::collection($referrals),
        ]);
    }

    /** Promociones vigentes visibles en el portal que aplican al plan del cliente. */
    protected function promotionsFor(Client $client): \Illuminate\Support\Collection
    {
        return Promotion::query()->current()->where('show_in_portal', true)->latest('id')->get()
            ->filter(fn (Promotion $p) => empty($p->plan_ids) || in_array((int) $client->plan_id, array_map('intval', (array) $p->plan_ids), true))
            ->values();
    }

    /** Próximo pago: saldo pendiente y su fecha límite, o la siguiente mensualidad. */
    protected function nextPayment(Client $client): ?array
    {
        $balance = (float) $client->balance;
        if ($balance > 0) {
            $amount = $balance;
            $date = $client->next_due_date;
        } else {
            if (! $client->plan) {
                return null;
            }
            $amount = $client->plan->priceBreakdown((float) $client->plan->price * $client->plan->periodMonths())['total'];
            $date = $client->next_due_date && $client->next_due_date->isFuture()
                ? $client->next_due_date
                : app(BillingService::class)->nextBillingDate($client);
        }
        $date = $date ? Carbon::parse($date) : null;

        return [
            'amount' => round($amount, 2),
            'amount_label' => money($amount),
            'date' => $date?->toDateString(),
            'date_label' => $date?->translatedFormat('j \d\e F'),
            'overdue' => $balance > 0 && $date && $date->lt(today()),
            'pending_balance' => $balance > 0,
        ];
    }

    protected function speedInName(Plan $plan): bool
    {
        return str_contains(mb_strtolower($plan->name), 'mbps') || str_contains($plan->name, (string) (float) $plan->download_mbps);
    }

    protected function profileData(Client $c): array
    {
        return [
            'code' => $c->code,
            'first_name' => $c->first_name,
            'last_name' => $c->last_name,
            'phone' => $c->phone,
            'whatsapp' => $c->whatsapp,
            'email' => $c->email,
            'address' => trim($c->address.' '.$c->colonia.' '.$c->community),
            'references' => $c->references,
            'pppoe_user' => $c->pppoe_user,
            'installation_date' => $c->installation_date?->toDateString(),
        ];
    }
}
