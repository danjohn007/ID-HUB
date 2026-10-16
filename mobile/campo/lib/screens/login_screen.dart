import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../core/api.dart';
import '../core/session.dart';
import '../theme.dart';

/// 1. Inicio / Login: usuario y contraseña del técnico o cobrador.
class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _form = GlobalKey<FormState>();
  final _email = TextEditingController();
  final _password = TextEditingController();
  bool _busy = false;
  bool _hide = true;
  String? _error;

  @override
  void dispose() {
    _email.dispose();
    _password.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_form.currentState!.validate()) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await context.read<Session>().login(_email.text, _password.text);
    } on ApiException catch (e) {
      setState(() => _error = e.detail);
    } catch (e) {
      setState(() => _error = '$e');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _editServer() async {
    final session = context.read<Session>();
    final ctrl = TextEditingController(text: session.server);
    final value = await showDialog<String>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Servidor'),
        content: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
          const Text('Dirección del panel de tu empresa (te la da el administrador).', style: TextStyle(color: AppColors.slate)),
          const SizedBox(height: 12),
          TextField(controller: ctrl, keyboardType: TextInputType.url, autocorrect: false, decoration: const InputDecoration(hintText: 'https://midominio.com')),
        ]),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('Cancelar')),
          FilledButton(onPressed: () => Navigator.pop(ctx, ctrl.text), child: const Text('Guardar')),
        ],
      ),
    );
    if (value != null) await session.setServer(value);
  }

  @override
  Widget build(BuildContext context) {
    final session = context.watch<Session>();

    return Scaffold(
      backgroundColor: AppColors.navy,
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(24),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 420),
              child: Column(children: [
                const SizedBox(height: 24),
                Image.asset('assets/brand/logo_stacked_dark.png', height: 150, semanticLabel: 'ID-Hub'),
                const SizedBox(height: 12),
                const Text('Técnicos y cobradores en acción', style: TextStyle(color: Color(0xFFB7C4DC))),
                const SizedBox(height: 32),
                Card(
                  child: Padding(
                    padding: const EdgeInsets.all(20),
                    child: Form(
                      key: _form,
                      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                        if (session.expiredMessage != null && _error == null) _Alert(session.expiredMessage!, color: AppColors.warning),
                        if (_error != null) _Alert(_error!, color: AppColors.danger),
                        TextFormField(
                          controller: _email,
                          keyboardType: TextInputType.emailAddress,
                          autocorrect: false,
                          textInputAction: TextInputAction.next,
                          autofillHints: const [AutofillHints.email],
                          decoration: const InputDecoration(prefixIcon: Icon(Icons.person_outline), labelText: 'Correo'),
                          validator: (v) => v == null || !v.contains('@') ? 'Escribe tu correo' : null,
                        ),
                        const SizedBox(height: 12),
                        TextFormField(
                          controller: _password,
                          obscureText: _hide,
                          textInputAction: TextInputAction.done,
                          autofillHints: const [AutofillHints.password],
                          onFieldSubmitted: (_) => _submit(),
                          decoration: InputDecoration(
                            prefixIcon: const Icon(Icons.lock_outline),
                            labelText: 'Contraseña',
                            suffixIcon: IconButton(icon: Icon(_hide ? Icons.visibility_outlined : Icons.visibility_off_outlined), onPressed: () => setState(() => _hide = !_hide)),
                          ),
                          validator: (v) => v == null || v.isEmpty ? 'Escribe tu contraseña' : null,
                        ),
                        const SizedBox(height: 20),
                        FilledButton(
                          onPressed: _busy ? null : _submit,
                          child: _busy ? const SizedBox(height: 22, width: 22, child: CircularProgressIndicator(strokeWidth: 2.5, color: Colors.white)) : const Text('Iniciar sesión'),
                        ),
                        const SizedBox(height: 8),
                        const Text('¿Olvidaste tu contraseña? Pídele al administrador que la restablezca desde el panel.',
                            textAlign: TextAlign.center, style: TextStyle(fontSize: 12, color: AppColors.slate)),
                      ]),
                    ),
                  ),
                ),
                const SizedBox(height: 20),
                TextButton.icon(
                  onPressed: _editServer,
                  icon: const Icon(Icons.dns_outlined, size: 18, color: Color(0xFFB7C4DC)),
                  label: Text(Uri.tryParse(session.server)?.host ?? session.server, style: const TextStyle(color: Color(0xFFB7C4DC))),
                ),
              ]),
            ),
          ),
        ),
      ),
    );
  }
}

class _Alert extends StatelessWidget {
  const _Alert(this.text, {required this.color});

  final String text;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return Container(
      margin: const EdgeInsets.only(bottom: 16),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(color: color.withValues(alpha: .1), borderRadius: BorderRadius.circular(12)),
      child: Text(text, style: TextStyle(color: color, fontWeight: FontWeight.w600)),
    );
  }
}
