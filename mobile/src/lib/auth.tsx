import Constants from 'expo-constants';
import * as SecureStore from 'expo-secure-store';
import { createContext, ReactNode, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import { Platform } from 'react-native';

import { api, DEFAULT_SERVER, normalizeServer } from './api';
import type { Tenant, User } from './types';

/**
 * Sesión del personal de campo: token Sanctum guardado en el almacén seguro del teléfono,
 * usuario, WISP y habilidades del token (permisos del rol, config/permissions.php).
 */

type Session = { token: string; user: User; tenant: Tenant | null; abilities: string[] };

type AuthState = {
  ready: boolean;
  session: Session | null;
  server: string;
  can: (...abilities: string[]) => boolean;
  login: (server: string, email: string, password: string) => Promise<void>;
  logout: () => Promise<void>;
  refresh: () => Promise<void>;
};

const KEY = 'wispcontrol.session';
const SERVER_KEY = 'wispcontrol.server';

const AuthContext = createContext<AuthState | null>(null);

export function AuthProvider({ children }: { children: ReactNode }) {
  const [ready, setReady] = useState(false);
  const [session, setSession] = useState<Session | null>(null);
  const [server, setServer] = useState(DEFAULT_SERVER);

  const clear = useCallback(async () => {
    api.setToken(null);
    setSession(null);
    await SecureStore.deleteItemAsync(KEY);
  }, []);

  // Al abrir la app: recupera servidor y sesión, y valida el token con /me
  useEffect(() => {
    (async () => {
      try {
        const savedServer = await SecureStore.getItemAsync(SERVER_KEY);
        if (savedServer) {
          api.setServer(savedServer);
          setServer(api.getServer());
        }
        const raw = await SecureStore.getItemAsync(KEY);
        if (raw) {
          const saved = JSON.parse(raw) as Session;
          api.setToken(saved.token);
          setSession(saved);
          try {
            const me = await api.get<{ user: User; tenant: Tenant | null; abilities: string[] }>('/me');
            const fresh = { ...saved, user: me.user, tenant: me.tenant, abilities: me.abilities ?? saved.abilities };
            setSession(fresh);
            await SecureStore.setItemAsync(KEY, JSON.stringify(fresh));
          } catch {
            // Sin conexión: se conserva la sesión guardada (el 401 la cierra vía onUnauthorized)
          }
        }
      } finally {
        setReady(true);
      }
    })();
  }, []);

  useEffect(() => {
    api.onUnauthorized(() => {
      clear();
    });
    return () => api.onUnauthorized(null);
  }, [clear]);

  const login = useCallback(async (serverUrl: string, email: string, password: string) => {
    const url = normalizeServer(serverUrl || DEFAULT_SERVER);
    api.setServer(url);
    const deviceName = `${Constants.deviceName ?? 'Teléfono'} (${Platform.OS}) · App de campo`.slice(0, 120);
    const res = await api.post<{ token: string; user: User; tenant: Tenant | null; abilities: string[] }>('/auth/login', {
      email: email.trim().toLowerCase(),
      password,
      device_name: deviceName,
    });
    const next = { token: res.token, user: res.user, tenant: res.tenant, abilities: res.abilities };
    if (!fieldAbilities.some((a) => next.abilities.includes(a) || next.abilities.includes('*'))) {
      api.setToken(res.token);
      await api.post('/auth/logout').catch(() => undefined);
      api.setToken(null);
      throw new Error('Tu usuario no tiene funciones de campo (técnico o cobrador). Usa el panel web.');
    }
    api.setToken(res.token);
    await SecureStore.setItemAsync(SERVER_KEY, url);
    await SecureStore.setItemAsync(KEY, JSON.stringify(next));
    setServer(url);
    setSession(next);
  }, []);

  const logout = useCallback(async () => {
    try {
      await api.post('/auth/logout');
    } catch {
      // aunque falle la red, se cierra la sesión local
    }
    await clear();
  }, [clear]);

  const refresh = useCallback(async () => {
    if (!session) return;
    const me = await api.get<{ user: User; tenant: Tenant | null; abilities: string[] }>('/me');
    const fresh = { ...session, user: me.user, tenant: me.tenant, abilities: me.abilities ?? session.abilities };
    setSession(fresh);
    await SecureStore.setItemAsync(KEY, JSON.stringify(fresh));
  }, [session]);

  const can = useCallback(
    (...abilities: string[]) => !!session && abilities.some((a) => session.abilities.includes('*') || session.abilities.includes(a)),
    [session],
  );

  const value = useMemo(() => ({ ready, session, server, can, login, logout, refresh }), [ready, session, server, can, login, logout, refresh]);

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

/** Habilidades que dan acceso a la app de campo. */
export const fieldAbilities = ['field.work', 'collection.field'];

export function useAuth(): AuthState {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth debe usarse dentro de AuthProvider');
  return ctx;
}
