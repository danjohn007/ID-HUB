<?php

namespace App\Livewire\Automations;

use App\Livewire\Concerns\Toasts;
use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Services\Automation\RuleCatalog;
use App\Services\AutomationEngine;
use App\Support\Audit;
use App\Support\InputRules;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Constructor visual de reglas  Evento → Condición → Acción (sección 46), con prueba en seco
 * contra un sujeto real y ejecución manual.
 */
class Edit extends Component
{
    use Toasts;

    public ?AutomationRule $rule = null;

    public array $form = [];

    public string $newAction = '';

    // Prueba
    public string $subjectSearch = '';

    public ?int $subjectId = null;

    public array $context = [];

    public ?array $test = null;

    public ?array $runResult = null;

    public function mount(?AutomationRule $rule = null): void
    {
        $this->rule = $rule?->exists ? $rule : null;

        if ($this->rule) {
            $this->form = [
                'name' => $this->rule->name,
                'description' => (string) $this->rule->description,
                'event' => $this->rule->event,
                'active' => (bool) $this->rule->active,
                'conditions' => array_values($this->rule->conditions ?? []),
                'actions' => array_values(array_map(fn ($a) => ['type' => $a['type'] ?? '', 'params' => array_merge(RuleCatalog::actionDefaults($a['type'] ?? ''), $a['params'] ?? [])], $this->rule->actions ?? [])),
            ];
        } else {
            $this->form = ['name' => '', 'description' => '', 'event' => 'payment_overdue', 'active' => true, 'conditions' => [], 'actions' => []];
            if (($tpl = request()->query('plantilla')) && ($t = RuleCatalog::templates()[$tpl] ?? null)) {
                $this->applyTemplate($tpl);
            }
        }
    }

    public function applyTemplate(string $key): void
    {
        $t = RuleCatalog::templates()[$key] ?? null;
        if (! $t) {
            return;
        }
        $this->form = array_merge($this->form, [
            'name' => $t['name'], 'description' => $t['description'], 'event' => $t['event'],
            'conditions' => $t['conditions'],
            'actions' => array_map(fn ($a) => ['type' => $a['type'], 'params' => array_merge(RuleCatalog::actionDefaults($a['type']), $a['params'])], $t['actions']),
        ]);
        $this->resetTest();
    }

    public function updatedFormEvent(): void
    {
        $fields = RuleCatalog::fields($this->form['event']);
        $this->form['conditions'] = array_values(array_filter($this->form['conditions'], fn ($c) => isset($fields[$c['field'] ?? ''])));
        $this->resetTest();
    }

    protected function resetTest(): void
    {
        $this->subjectId = null;
        $this->subjectSearch = '';
        $this->context = [];
        $this->test = null;
        $this->runResult = null;
    }

    // ------------------------------------------------------------ Condiciones

    public function addCondition(): void
    {
        $fields = RuleCatalog::fields($this->form['event']);
        $field = array_key_first($fields);
        $this->form['conditions'][] = ['field' => $field, 'operator' => $fields[$field][1] === 'number' ? '>=' : '=', 'value' => $fields[$field][1] === 'bool' ? '1' : ''];
    }

    public function removeCondition(int $i): void
    {
        unset($this->form['conditions'][$i]);
        $this->form['conditions'] = array_values($this->form['conditions']);
    }

    public function updatedForm($value, $key): void
    {
        // Al cambiar el campo de una condición, ajusta operador y valor al tipo
        if (preg_match('/^conditions\.(\d+)\.field$/', $key, $m)) {
            $def = RuleCatalog::fields($this->form['event'])[$value] ?? null;
            $i = (int) $m[1];
            $this->form['conditions'][$i]['value'] = $def && $def[1] === 'bool' ? '1' : '';
            $this->form['conditions'][$i]['operator'] = match ($def[1] ?? 'text') { 'number' => '>=', default => '=' };
        }
        if (preg_match('/^conditions\.(\d+)\.operator$/', $key, $m) && $value === 'in') {
            $this->form['conditions'][(int) $m[1]]['value'] = '';
        }
    }

    public function setBool(int $i, bool $yes): void
    {
        $this->form['conditions'][$i]['operator'] = $yes ? '=' : '!=';
        $this->form['conditions'][$i]['value'] = '1';
    }

    public function toggleIn(int $i, string $key): void
    {
        $values = array_filter(array_map('trim', explode(',', (string) ($this->form['conditions'][$i]['value'] ?? ''))), 'strlen');
        $values = in_array($key, $values, true) ? array_diff($values, [$key]) : [...$values, $key];
        $this->form['conditions'][$i]['value'] = implode(',', $values);
    }

    // ------------------------------------------------------------ Acciones

    public function addAction(): void
    {
        if (! array_key_exists($this->newAction, config('wisp.automation_actions'))) {
            return;
        }
        $this->form['actions'][] = ['type' => $this->newAction, 'params' => RuleCatalog::actionDefaults($this->newAction)];
        $this->newAction = '';
    }

    public function removeAction(int $i): void
    {
        unset($this->form['actions'][$i]);
        $this->form['actions'] = array_values($this->form['actions']);
    }

    public function moveAction(int $i, int $dir): void
    {
        $j = $i + $dir;
        if (! isset($this->form['actions'][$i], $this->form['actions'][$j])) {
            return;
        }
        [$this->form['actions'][$i], $this->form['actions'][$j]] = [$this->form['actions'][$j], $this->form['actions'][$i]];
    }

    // ------------------------------------------------------------ Guardar

    protected function rules(): array
    {
        $rules = [
            'form.name' => 'required|string|max:190|'.InputRules::TEXT,
            'form.description' => 'nullable|string|max:190',
            'form.event' => ['required', Rule::in(array_keys(config('wisp.automation_events')))],
            'form.active' => 'boolean',
            'form.conditions' => 'array',
            'form.conditions.*.field' => ['required', Rule::in(array_keys(RuleCatalog::fields($this->form['event'] ?? '')))],
            'form.conditions.*.operator' => ['required', Rule::in(array_keys(RuleCatalog::OPERATORS))],
            'form.conditions.*.value' => 'required|string|max:190',
            'form.actions' => 'required|array|min:1',
            'form.actions.*.type' => ['required', Rule::in(array_keys(config('wisp.automation_actions')))],
        ];
        foreach ($this->form['actions'] ?? [] as $i => $a) {
            $p = "form.actions.$i.params";
            $rules += match ($a['type'] ?? '') {
                'send_message' => ["$p.event" => ['required', Rule::in(array_keys(config('wisp.message_events')))], "$p.channels" => 'array', "$p.channels.*" => Rule::in(array_keys(config('wisp.channels')))],
                'notify_staff' => ["$p.roles" => 'required|array|min:1', "$p.message" => 'required|string|max:500'],
                'create_ticket' => ["$p.category" => ['required', Rule::in(array_keys(config('wisp.ticket_categories')))], "$p.priority" => ['required', Rule::in(array_keys(config('wisp.ticket_priorities')))], "$p.subject" => 'required|string|max:190', "$p.description" => 'nullable|string|max:1000'],
                'create_alert' => ["$p.severity" => ['required', Rule::in(array_keys(config('wisp.alert_severity')))], "$p.title" => 'required|string|max:190', "$p.message" => 'nullable|string|max:500', "$p.alert_type" => ['required', Rule::in(array_keys(RuleCatalog::alertTypes()))]],
                'set_status' => ["$p.status" => ['required', Rule::in(array_keys(config('wisp.client_status')))]],
                'change_plan' => ["$p.plan_id" => ['required', Rule::exists('plans', 'id')->where('tenant_id', tenant()->id)], "$p.keep_billing" => 'boolean'],
                'address_list' => ["$p.list" => ['required', 'string', 'max:60', 'regex:/^[A-Za-z0-9_.-]+$/'], "$p.operation" => ['required', Rule::in(['add', 'remove'])]],
                default => [],
            };
        }

        return $rules;
    }

    protected function messages(): array
    {
        return [
            'form.actions.required' => 'Agrega al menos una acción.',
            'form.actions.min' => 'Agrega al menos una acción.',
            'form.conditions.*.value.required' => 'Indica un valor.',
            'form.actions.*.params.roles.required' => 'Elige al menos un rol.',
            'form.actions.*.params.message.required' => 'Escribe el mensaje.',
            'form.actions.*.params.subject.required' => 'Escribe el asunto del ticket.',
            'form.actions.*.params.title.required' => 'Escribe el título de la alerta.',
            'form.actions.*.params.plan_id.required' => 'Elige el plan destino.',
            'form.actions.*.params.list.required' => 'Escribe el nombre de la address-list.',
            'form.actions.*.params.list.regex' => 'Solo letras, números, guion, guion bajo y punto (sin espacios).',
        ];
    }

    protected function payload(): array
    {
        return [
            'name' => $this->form['name'],
            'description' => $this->form['description'] ?: null,
            'event' => $this->form['event'],
            'active' => (bool) $this->form['active'],
            'conditions' => array_values(array_map(fn ($c) => ['field' => $c['field'], 'operator' => $c['operator'], 'value' => (string) $c['value']], $this->form['conditions'])),
            'actions' => array_values(array_map(function ($a) {
                $params = $a['params'] ?? [];
                foreach (['notify', 'require_zero_balance', 'keep_billing'] as $bool) {
                    if (array_key_exists($bool, $params)) {
                        $params[$bool] = (bool) $params[$bool];
                    }
                }
                if (isset($params['plan_id'])) {
                    $params['plan_id'] = (int) $params['plan_id'];
                }
                if (isset($params['channels'])) {
                    $params['channels'] = array_values($params['channels']);
                    if (! $params['channels']) {
                        unset($params['channels']); // vacío = canales predeterminados del WISP
                    }
                }

                return ['type' => $a['type'], 'params' => $params];
            }, $this->form['actions'])),
        ];
    }

    public function save()
    {
        $this->validate();
        $data = $this->payload();

        if ($this->rule) {
            if ($this->rule->system) {
                $data['name'] = $this->rule->name; // las reglas del sistema conservan su nombre
            }
            $this->rule->update($data);
            $this->toast('Regla guardada');

            return null;
        }

        $this->rule = AutomationRule::create($data + ['system' => false]);
        session()->flash('success', 'Regla creada');

        return $this->redirectRoute('automations.edit', $this->rule, navigate: true);
    }

    public function delete()
    {
        if (! $this->rule || $this->rule->system) {
            $this->toast('Las reglas del sistema no se pueden eliminar; puedes desactivarlas', 'warning');

            return null;
        }
        Audit::log('deleted', "eliminó la automatización {$this->rule->name}", $this->rule);
        $this->rule->delete();
        session()->flash('success', 'Regla eliminada');

        return $this->redirectRoute('automations.index', navigate: true);
    }

    // ------------------------------------------------------------ Prueba

    public function selectSubject(int $id): void
    {
        $this->subjectId = $id;
        $this->subjectSearch = '';
        $this->test = null;
        $this->runResult = null;
    }

    protected function testContext(): array
    {
        $ctx = [];
        foreach (RuleCatalog::contextFields($this->form['event']) as $key => $label) {
            $v = trim((string) ($this->context[$key] ?? ''));
            if ($v !== '') {
                $ctx[$key] = is_numeric($v) && $key !== 'folio' ? $v + 0 : $v;
            }
        }

        return $ctx;
    }

    public function dryRun(AutomationEngine $engine): void
    {
        $subject = RuleCatalog::findSubject(RuleCatalog::subjectFor($this->form['event']), $this->subjectId);
        if (! $subject) {
            $this->toast('Elige un '.mb_strtolower(RuleCatalog::SUBJECTS[RuleCatalog::subjectFor($this->form['event'])]).' para probar', 'warning');

            return;
        }
        $rule = new AutomationRule($this->payload());
        $rule->tenant_id = tenant()->id;
        $result = $engine->dryRun($rule, $subject, $this->testContext());

        $event = $this->form['event'];
        $fields = RuleCatalog::fields($event);
        $hasClient = $subject instanceof \App\Models\Client || ! empty($subject->getAttribute('client_id'));
        $this->test = [
            'matches' => $result['matches'],
            'facts' => collect($result['facts'])->map(fn ($v, $k) => [
                'label' => $fields[$k][0] ?? $k,
                'value' => is_bool($v) ? ($v ? 'Sí' : 'No') : ($v === null || $v === '' ? '—' : RuleCatalog::valueLabel($event, $k, $v)),
            ])->values()->all(),
            'conditions' => array_map(fn ($c) => [
                'text' => RuleCatalog::describeCondition($event, $c),
                'actual' => is_bool($c['actual']) ? ($c['actual'] ? 'Sí' : 'No') : ($c['actual'] === null ? '—' : RuleCatalog::valueLabel($event, $c['field'], $c['actual'])),
                'ok' => $c['ok'],
            ], $result['conditions']),
            'actions' => array_map(fn ($a) => RuleCatalog::describeAction($a)
                .(in_array($a['type'] ?? '', RuleCatalog::CLIENT_ACTIONS) && ! $hasClient ? ' · sin cliente: se omitirá' : ''), $result['actions']),
        ];
        $this->runResult = null;
    }

    public function runNow(AutomationEngine $engine): void
    {
        if (! $this->rule) {
            $this->toast('Guarda la regla antes de ejecutarla', 'warning');

            return;
        }
        $subject = RuleCatalog::findSubject(RuleCatalog::subjectFor($this->rule->event), $this->subjectId);
        if (! $subject) {
            $this->toast('Elige un sujeto para ejecutar la regla', 'warning');

            return;
        }
        $run = $engine->runRule($this->rule->fresh(), $subject, $this->testContext());
        Audit::log('updated', "ejecutó manualmente la automatización {$this->rule->name}", $this->rule);
        $this->runResult = ['status' => $run->status, 'log' => $run->log ?? []];
        $this->rule->refresh();
        $this->toast($run->status === 'success' ? 'Regla ejecutada' : ($run->status === 'skipped' ? 'Condiciones no cumplidas: no se ejecutó' : 'La ejecución tuvo errores'), $run->status === 'failed' ? 'error' : ($run->status === 'skipped' ? 'warning' : 'success'));
    }

    public function render()
    {
        $event = $this->form['event'];
        $subjectType = RuleCatalog::subjectFor($event);

        return view('livewire.automations.edit', [
            'fields' => RuleCatalog::fields($event),
            'subjectType' => $subjectType,
            'subjectLabel' => RuleCatalog::SUBJECTS[$subjectType],
            'subjectResults' => strlen(trim($this->subjectSearch)) >= 1 || ! $this->subjectId ? RuleCatalog::searchSubjects($subjectType, $this->subjectSearch, 8) : [],
            'subjectName' => $this->subjectId ? RuleCatalog::describeSubject(RuleCatalog::findSubject($subjectType, $this->subjectId)) : null,
            'contextFields' => RuleCatalog::contextFields($event),
            'runs' => $this->rule ? AutomationRun::query()->where('automation_rule_id', $this->rule->id)->latest()->limit(15)->get() : collect(),
            'templates' => RuleCatalog::templates(),
        ])->title($this->rule ? 'Editar automatización' : 'Nueva automatización');
    }
}
