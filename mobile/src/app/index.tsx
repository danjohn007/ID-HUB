import { Redirect } from 'expo-router';

import { useAuth } from '@/lib/auth';
import { visibleTabs } from '@/lib/tabs';

/** Entrada: login o la primera pestaña del rol (Jornada para técnicos, Cobranza para cobradores). */
export default function Index() {
  const { session, can } = useAuth();
  if (!session) return <Redirect href="/login" />;
  return <Redirect href={`/(tabs)/${visibleTabs(can)[0]}`} />;
}
