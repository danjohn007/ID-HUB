/** Tipos de las respuestas de la API v1 (docs/API.md). */

export type Catalog = { value: string | null; label: string | null; color: string | null };
export type Coords = { lat: number; lng: number };

export type Tenant = {
  id: number;
  name: string;
  slug: string;
  logo_url: string | null;
  primary_color: string | null;
  secondary_color: string | null;
  currency: string | null;
  timezone: string | null;
  white_label?: boolean;
};

export type User = {
  id: number;
  name: string;
  email: string;
  phone: string | null;
  role: string;
  role_label: string;
  tenant_id: number | null;
  last_location: Coords | null;
  last_location_at: string | null;
};

export type ClientSummary = {
  id: number;
  code: string;
  full_name: string;
  phone: string | null;
  address: string;
  location: Coords | null;
  service_status: Catalog;
  balance: number;
};

export type Client = ClientSummary & {
  first_name: string;
  last_name: string;
  whatsapp: string | null;
  email: string | null;
  references: string | null;
  colonia: string | null;
  community: string | null;
  navigation_url: string | null;
  zone?: { id: number; name: string } | null;
  plan?: { id: number; name: string; download_mbps: number; upload_mbps: number; speed_label: string; price: number } | null;
  repeater?: { id: number; code: string; name: string } | null;
  online: boolean;
  signal_dbm: number | null;
  ip_address: string | null;
  pppoe_user: string | null;
  credit_balance: number;
  next_due_date: string | null;
  installation_date: string | null;
  notes: string | null;
};

export type Attachment = {
  id: number;
  kind: string;
  kind_label: string;
  url: string;
  original_name: string;
  mime: string;
  is_image: boolean;
  location: Coords | null;
  taken_at: string | null;
};

export type Material = {
  id: number;
  type: Catalog;
  item: { id: number; sku: string; name: string; unit: string } | null;
  quantity: number;
  serial: string | null;
  returnable: boolean;
  user: { id: number; name: string } | null;
  created_at: string;
};

export type Comment = {
  id: number;
  type: string;
  body: string;
  internal: boolean;
  author: string;
  created_at: string;
};

export type Ticket = {
  id: number;
  folio: string;
  subject: string;
  description: string | null;
  category: { value: string; label: string };
  priority: Catalog;
  status: Catalog;
  client?: ClientSummary | null;
  client_detail?: Client;
  technician?: { id: number; name: string } | null;
  repeater?: { id: number; code: string; name: string; location: Coords | null } | null;
  installation_order_id: number | null;
  open: boolean;
  navigation_url: string | null;
  sla: { due_at: string | null; state: string | null; breached: boolean };
  on_way_at: string | null;
  arrived_at: string | null;
  departed_at: string | null;
  resolved_at: string | null;
  resolution: string | null;
  comments?: Comment[];
  attachments?: Attachment[];
  materials?: Material[];
  signature: { url: string; signed_by_name: string; signed_at: string | null } | null;
  created_at: string;
};

export type Stop = {
  id: number | null;
  key: string;
  ref_id: number;
  sequence: number;
  status: 'pending' | 'arrived' | 'done' | 'skipped';
  status_label: Catalog;
  type: 'ticket' | 'installation_order' | 'client';
  title: string | null;
  subtitle: string | null;
  address: string | null;
  phone: string | null;
  priority: string | null;
  badge: { label: string; color: string } | null;
  sla: string | null;
  location: Coords | null;
  navigation_url: string | null;
  arrived_at: string | null;
  departed_at: string | null;
};

export type FieldDay = {
  date: string;
  route: { id: number; status: string; maps_url: string | null; stops_total: number; stops_done: number } | null;
  suggested: boolean;
  stops: Stop[];
  summary: { stops: number; done: number; distance_km: number; progress: number };
  start: (Coords & { label?: string }) | null;
  maps_url: string | null;
  open_tickets: Ticket[];
  message: string | null;
};

export type ChecklistItem = { key: string; label: string; done: boolean; auto: boolean };

export type InstallationOrder = {
  id: number;
  folio: string;
  status: Catalog;
  step: number;
  can_start: boolean;
  editable: boolean;
  customer_name: string;
  phone: string | null;
  address: string | null;
  references: string | null;
  location: Coords | null;
  navigation_url: string | null;
  client: ClientSummary | null;
  plan?: { id: number; name: string; speed: string; price: number } | null;
  repeater?: { id: number; code: string; name: string } | null;
  scheduled_at: string | null;
  started_at: string | null;
  completed_at: string | null;
  quote_amount: number;
  notes: string | null;
  checklist: ChecklistItem[];
  checklist_progress: number;
  measures: { signal_dbm: number | null; speed_down: number | null; speed_up: number | null };
  signature: { url: string; signed_by_name: string } | null;
  attachments?: Attachment[];
  materials?: Material[];
};

export type InventoryItem = {
  id: number;
  sku: string;
  name: string;
  category: { value: string; label: string } | null;
  unit: string;
  stock: number;
  stock_label: string;
  stock_state: string;
};

export type Visit = {
  id: number;
  client_id: number;
  result: Catalog;
  amount_due: number;
  promise_date: string | null;
  promise_amount: number | null;
  payment?: Payment | null;
  notes: string | null;
  visited_at: string;
};

export type Payment = {
  id: number;
  folio: string;
  client_id: number;
  client?: ClientSummary;
  method: string;
  method_label: string;
  amount: number;
  amount_label: string;
  reference: string | null;
  status: Catalog;
  paid_at: string;
  collector?: { id: number; name: string } | null;
  receipt_url: string | null;
};

export type Charge = {
  id: number;
  folio: string;
  type: string;
  type_label: string;
  description: string;
  total: number;
  balance: number;
  due_date: string;
  overdue: boolean;
  status: Catalog;
};

export type CashCut = {
  id: number;
  folio: string;
  status: Catalog;
  opened_at: string | null;
  closed_at: string | null;
  payments_count: number;
  expected_amount: number;
  delivered_amount: number | null;
  difference: number | null;
  reconciled_at: string | null;
  reconciled_by?: { id: number; name: string } | null;
  notes: string | null;
};

export type CollectionClient = ClientSummary & {
  position: number | null;
  whatsapp: string | null;
  colonia: string | null;
  references: string | null;
  plan: { id: number; name: string } | null;
  zone: { id: number; name: string } | null;
  overdue_days: number;
  next_due_date: string | null;
  route_seq: number | null;
  distance_km: number | null;
  navigation_url: string;
  visit_today: Visit | null;
};

export type CollectionDay = {
  date: string;
  view: 'pendientes' | 'visitados';
  summary: { collected: number; payments: number; visits: number; pending: number; pending_amount: number };
  cash_cut: CashCut | null;
  route: { id: number; stops_total: number; stops_done: number; maps_url: string | null } | null;
  ordered_by: 'route' | 'distance' | 'balance';
  counts: { pendientes: number; visitados: number };
  data: CollectionClient[];
};

export type Paginated<T> = {
  data: T[];
  meta?: { current_page: number; last_page: number; per_page: number; total: number };
};
