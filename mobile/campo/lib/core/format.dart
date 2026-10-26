import 'package:intl/intl.dart';

/// Formatos en español de México: dinero, fechas y horas.
class Fmt {
  static final _money = NumberFormat.currency(locale: 'es_MX', symbol: r'$', decimalDigits: 2);
  static final _qty = NumberFormat('#,##0.##', 'es_MX');

  static String money(num? v) => _money.format(v ?? 0);

  static String qty(num? v) => _qty.format(v ?? 0);

  static DateTime? parse(String? iso) => iso == null ? null : DateTime.tryParse(iso)?.toLocal();

  /// 15/10/2026
  static String date(String? iso) {
    final d = parse(iso);
    return d == null ? '—' : DateFormat('dd/MM/yyyy', 'es_MX').format(d);
  }

  /// 10:24
  static String time(String? iso) {
    final d = parse(iso);
    return d == null ? '—' : DateFormat('HH:mm', 'es_MX').format(d);
  }

  /// 15/10 10:24
  static String dateTime(String? iso) {
    final d = parse(iso);
    return d == null ? '—' : DateFormat('dd/MM HH:mm', 'es_MX').format(d);
  }

  /// Tiempo entre dos horas: "35 min", "1 h 05 min" (null si falta alguna).
  static String? span(String? fromIso, String? toIso) {
    final from = parse(fromIso), to = parse(toIso);
    if (from == null || to == null || to.isBefore(from)) return null;
    final m = to.difference(from).inMinutes;
    return m < 60 ? '$m min' : '${m ~/ 60} h ${(m % 60).toString().padLeft(2, '0')} min';
  }

  /// mié, 15 oct 2026
  static String longDate(DateTime d) => DateFormat('EEE, d MMM yyyy', 'es_MX').format(d);

  static String apiDate(DateTime d) => DateFormat('yyyy-MM-dd').format(d);

  /// "hace 5 min", "en 2 h" (para el SLA).
  static String relative(String? iso) {
    final d = parse(iso);
    if (d == null) return '—';
    final diff = d.difference(DateTime.now());
    final abs = diff.abs();
    final txt = abs.inMinutes < 60
        ? '${abs.inMinutes} min'
        : abs.inHours < 48
            ? '${abs.inHours} h'
            : '${abs.inDays} d';
    return diff.isNegative ? 'hace $txt' : 'en $txt';
  }

  static String greeting() {
    final h = DateTime.now().hour;
    if (h < 12) return 'Buenos días';
    if (h < 19) return 'Buenas tardes';
    return 'Buenas noches';
  }
}
