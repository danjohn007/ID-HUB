import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { router } from 'expo-router';
import { useEffect, useState } from 'react';
import { Pressable, StyleSheet, View } from 'react-native';
import { ChevronRightIcon, UsersIcon } from 'react-native-heroicons/outline';
import { MapPinIcon } from 'react-native-heroicons/solid';

import { RequireAbility, Screen } from '@/components/Screen';
import { Avatar, Card, Empty, ErrorBox, Input, Loading, Row, Segmented, Status, Text } from '@/components/ui';
import { api, errorMessage } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { money } from '@/lib/format';
import type { Client, Paginated } from '@/lib/types';
import { colors, radius, shadow } from '@/theme';

/** Consulta de clientes (sección 23: «Ver clientes»). */
export default function ClientesScreen() {
  return (
    <RequireAbility ability="clients.view">
      <Clientes />
    </RequireAbility>
  );
}

function Clientes() {
  const { session, can } = useAuth();
  const [search, setSearch] = useState('');
  const [term, setTerm] = useState('');
  const [scope, setScope] = useState<'mine' | 'all'>('mine');

  useEffect(() => {
    const t = setTimeout(() => setTerm(search.trim()), 300);
    return () => clearTimeout(t);
  }, [search]);

  // "Mis clientes": los asignados como técnico responsable o como cobrador
  const mineKey = can('collection.field') && !can('field.work') ? 'collector_id' : 'technician_id';
  const q = useQuery({
    queryKey: ['clients', term, scope],
    queryFn: () =>
      api.get<Paginated<Client>>('/clients', {
        search: term,
        per_page: 40,
        sort: 'first_name',
        [mineKey]: scope === 'mine' && !term ? session?.user.id : undefined,
      }),
    placeholderData: keepPreviousData,
  });
  const list = q.data?.data ?? [];

  return (
    <Screen tab onRefresh={() => q.refetch()}>
      <View>
        <Text weight="bold" style={{ fontSize: 24, color: colors.navy[900] }}>
          Clientes
        </Text>
        <Text style={{ color: colors.slate[500], fontSize: 13.5 }}>Busca por nombre, ID de cliente, teléfono o dirección.</Text>
      </View>
      <Input placeholder="Buscar cliente…" value={search} onChangeText={setSearch} autoCorrect={false} returnKeyType="search" />
      {!term ? (
        <Segmented
          options={[
            { value: 'mine', label: 'Mis clientes' },
            { value: 'all', label: 'Todos' },
          ]}
          value={scope}
          onChange={setScope}
        />
      ) : null}
      {q.isLoading ? <Loading /> : null}
      {q.error ? <ErrorBox message={errorMessage(q.error)} onRetry={() => q.refetch()} /> : null}
      {!q.isLoading && !list.length && !q.error ? (
        <Card padded={false}>
          <Empty icon={UsersIcon} title={term ? 'Sin coincidencias' : 'Sin clientes asignados'} description={term ? 'Prueba con otro nombre o número.' : 'Cambia a «Todos» para ver todos los clientes.'} />
        </Card>
      ) : null}
      {list.map((c) => (
        <Pressable key={c.id} onPress={() => router.push(`/cliente/${c.id}`)} style={({ pressed }) => [styles.card, pressed ? { opacity: 0.9 } : null]}>
          <Avatar name={c.full_name} size={40} />
          <View style={{ flex: 1, gap: 3 }}>
            <Text weight="semibold" numberOfLines={1}>
              {c.full_name}
            </Text>
            <Row gap={6}>
              <Text style={{ fontSize: 12, color: colors.slate[500] }}>
                #{c.code}
                {c.plan ? ` · ${c.plan.name}` : ''}
              </Text>
              <Status value={c.service_status} />
            </Row>
            {c.address ? (
              <Row gap={4}>
                <MapPinIcon size={12} color={colors.slate[400]} />
                <Text style={{ fontSize: 12, color: colors.slate[500], flex: 1 }} numberOfLines={1}>
                  {c.address}
                  {c.colonia ? `, ${c.colonia}` : ''}
                </Text>
              </Row>
            ) : null}
          </View>
          <View style={{ alignItems: 'flex-end', gap: 2 }}>
            {c.balance > 0 ? (
              <Text weight="bold" style={{ color: colors.danger[600] }}>
                {money(c.balance)}
              </Text>
            ) : null}
            <ChevronRightIcon size={18} color={colors.slate[300]} />
          </View>
        </Pressable>
      ))}
    </Screen>
  );
}

const styles = StyleSheet.create({
  card: { flexDirection: 'row', alignItems: 'center', gap: 12, backgroundColor: colors.white, borderRadius: radius.lg, borderWidth: 1, borderColor: colors.slate[200], padding: 14, ...shadow.card },
});
