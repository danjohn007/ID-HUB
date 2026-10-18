import { useState } from 'react';
import { KeyboardAvoidingView, Platform, Pressable, ScrollView, StyleSheet, View } from 'react-native';
import { ArrowRightOnRectangleIcon, ServerIcon } from 'react-native-heroicons/outline';
import { SafeAreaView } from 'react-native-safe-area-context';

import { Logo } from '@/components/Logo';
import { Button, Card, Input, Text } from '@/components/ui';
import { ApiError, errorMessage } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { colors } from '@/theme';

/** Inicio de sesión del personal de campo (mismas credenciales del panel web). */
export default function Login() {
  const { login, server } = useAuth();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [serverUrl, setServerUrl] = useState(server);
  // Sin servidor configurado (EXPO_PUBLIC_API_URL) se pide en el formulario
  const [showServer, setShowServer] = useState(!server);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string | undefined>>({});

  const submit = async () => {
    setError(null);
    setFieldErrors({});
    if (!email.trim() || !password || !serverUrl.trim()) {
      setFieldErrors({
        email: !email.trim() ? 'Escribe tu correo.' : undefined,
        password: !password ? 'Escribe tu contraseña.' : undefined,
        server: !serverUrl.trim() ? 'Escribe la dirección del sistema (la misma del panel web).' : undefined,
      });
      setShowServer(true);
      return;
    }
    setLoading(true);
    try {
      await login(serverUrl, email, password);
    } catch (e) {
      if (e instanceof ApiError && e.status === 422) {
        setFieldErrors({ email: e.field('email'), password: e.field('password') });
      }
      setError(errorMessage(e));
    } finally {
      setLoading(false);
    }
  };

  return (
    <View style={{ flex: 1, backgroundColor: colors.navy[900] }}>
      <View style={styles.circleA} />
      <View style={styles.circleB} />
      <SafeAreaView style={{ flex: 1 }}>
        <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={{ flex: 1 }}>
          <ScrollView contentContainerStyle={styles.scroll} keyboardShouldPersistTaps="handled">
            <View style={{ alignItems: 'center', marginBottom: 28 }}>
              <Logo size="lg" />
              <Text weight="medium" style={{ color: colors.navy[200], marginTop: 14, fontSize: 14 }}>
                App de campo · Técnicos y cobradores
              </Text>
            </View>

            <Card style={{ padding: 20, gap: 14 }}>
              <View>
                <Text weight="bold" style={{ fontSize: 20, color: colors.navy[900] }}>
                  Iniciar sesión
                </Text>
                <Text style={{ color: colors.slate[500], fontSize: 13, marginTop: 2 }}>Usa el mismo correo y contraseña del panel.</Text>
              </View>

              {error ? (
                <View style={styles.error}>
                  <Text weight="medium" style={{ color: colors.danger[700], fontSize: 13 }}>
                    {error}
                  </Text>
                </View>
              ) : null}

              <Input
                label="Correo electrónico"
                value={email}
                onChangeText={setEmail}
                autoCapitalize="none"
                autoComplete="email"
                keyboardType="email-address"
                textContentType="username"
                placeholder="tu@empresa.com"
                error={fieldErrors.email}
              />
              <Input
                label="Contraseña"
                value={password}
                onChangeText={setPassword}
                secureTextEntry
                autoComplete="password"
                textContentType="password"
                placeholder="••••••••"
                error={fieldErrors.password}
                onSubmitEditing={submit}
                returnKeyType="go"
              />

              {showServer ? (
                <Input
                  label="Servidor"
                  value={serverUrl}
                  onChangeText={setServerUrl}
                  autoCapitalize="none"
                  autoCorrect={false}
                  keyboardType="url"
                  placeholder="https://tuwisp.com"
                  error={fieldErrors.server}
                />
              ) : null}

              <Button size="lg" icon={ArrowRightOnRectangleIcon} onPress={submit} loading={loading}>
                Entrar
              </Button>

              <Pressable onPress={() => setShowServer((v) => !v)} style={styles.serverLink} hitSlop={8}>
                <ServerIcon size={14} color={colors.slate[400]} />
                <Text style={{ color: colors.slate[500], fontSize: 12 }} numberOfLines={1}>
                  {showServer ? 'Ocultar servidor' : `Servidor: ${serverUrl.replace(/^https?:\/\//, '') || 'sin configurar'}`}
                </Text>
              </Pressable>
            </Card>
          </ScrollView>
        </KeyboardAvoidingView>
      </SafeAreaView>
    </View>
  );
}

const styles = StyleSheet.create({
  scroll: { flexGrow: 1, justifyContent: 'center', padding: 20 },
  circleA: { position: 'absolute', top: -80, right: -80, width: 260, height: 260, borderRadius: 130, backgroundColor: 'rgba(26,127,245,0.30)' },
  circleB: { position: 'absolute', bottom: -60, left: -90, width: 220, height: 220, borderRadius: 110, backgroundColor: 'rgba(26,127,245,0.15)' },
  error: { backgroundColor: colors.danger[50], borderColor: colors.danger[100], borderWidth: 1, borderRadius: 10, padding: 10 },
  serverLink: { flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 6 },
});
