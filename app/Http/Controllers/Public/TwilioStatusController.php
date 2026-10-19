<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\Tenant;
use App\Services\Messaging\MessageService;
use App\Services\Messaging\TwilioErrors;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Callback de estado de Twilio (StatusCallback de cada SMS / WhatsApp enviado).
 * El WISP se resuelve por el MessageSid y la petición se valida con la firma X-Twilio-Signature
 * usando el Auth token de ese WISP.
 */
class TwilioStatusController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $sid = (string) ($request->input('MessageSid') ?: $request->input('SmsSid'));
        $message = $sid ? Message::withoutGlobalScopes()->where('provider_id', $sid)->first() : null;
        if (! $message) {
            return response('', 204); // mensaje desconocido: se ignora para que Twilio no reintente
        }

        $cfg = Tenant::find($message->tenant_id)?->settingsFor('messaging') ?? [];
        if (! $this->validSignature($request, (string) ($cfg['twilio_token'] ?? ''))
            || ($cfg['twilio_sid'] ?? null) !== $request->input('AccountSid')) {
            return response('Firma inválida', 403);
        }

        $status = (string) $request->input('MessageStatus', $request->input('SmsStatus'));
        if (in_array($status, ['failed', 'undelivered'], true)) {
            $code = $request->input('ErrorCode');
            $message->update(['status' => 'failed', 'error' => mb_substr('Twilio'.($code ? " $code" : '').': '.(TwilioErrors::describe($code) ?? 'el mensaje no se entregó.'), 0, 250)]);
        } elseif (in_array($status, ['delivered', 'read'], true) && $message->status !== 'failed') {
            $message->update(['status' => 'delivered', 'error' => null]);
        }
        // queued / sending / sent: el mensaje ya está como "Enviado"; los avisos pueden llegar en desorden,
        // por eso nunca se regresa un "Entregado" a "Enviado".

        return response('', 204);
    }

    /** https://www.twilio.com/docs/usage/security#validating-requests */
    protected function validSignature(Request $request, string $token): bool
    {
        $signature = (string) $request->header('X-Twilio-Signature');
        if ($token === '' || $signature === '') {
            return false;
        }
        $params = $request->post();
        ksort($params);
        $data = '';
        foreach ($params as $key => $value) {
            $data .= $key.(is_array($value) ? implode('', $value) : $value);
        }
        // Twilio firma la URL exacta que llamó; detrás de un proxy la petición puede llegar como http.
        $urls = array_unique(array_filter([MessageService::twilioStatusUrl(), $request->fullUrl(), preg_replace('/^http:/', 'https:', $request->fullUrl())]));
        foreach ($urls as $url) {
            if (hash_equals(base64_encode(hash_hmac('sha1', $url.$data, $token, true)), $signature)) {
                return true;
            }
        }

        return false;
    }
}
