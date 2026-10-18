import * as Location from 'expo-location';

import type { Coords } from './types';

/**
 * Ubicación GPS del dispositivo (equivalente a wispGeo de resources/js/app.js).
 * Nunca lanza: si no hay permiso o señal devuelve null y la acción se registra "sin GPS".
 */
let last: { coords: Coords; at: number } | null = null;

export async function ensurePermission(): Promise<boolean> {
  const current = await Location.getForegroundPermissionsAsync();
  if (current.granted) return true;
  if (!current.canAskAgain) return false;
  const asked = await Location.requestForegroundPermissionsAsync();
  return asked.granted;
}

export async function currentPosition(timeoutMs = 12000): Promise<Coords | null> {
  try {
    if (!(await ensurePermission())) return null;
    // Una lectura de menos de 30 s sirve (ahorra batería y tiempo en campo)
    if (last && Date.now() - last.at < 30000) return last.coords;
    const fix = await Promise.race([
      Location.getCurrentPositionAsync({ accuracy: Location.Accuracy.High }),
      new Promise<null>((resolve) => setTimeout(() => resolve(null), timeoutMs)),
    ]);
    const pos = fix ?? (await Location.getLastKnownPositionAsync({ maxAge: 5 * 60 * 1000 }));
    if (!pos) return null;
    const coords = { lat: Number(pos.coords.latitude.toFixed(7)), lng: Number(pos.coords.longitude.toFixed(7)) };
    last = { coords, at: Date.now() };
    return coords;
  } catch {
    return null;
  }
}

/** Parámetros lat/lng listos para la API (vacíos si no hay GPS). */
export async function gpsParams(): Promise<{ lat?: number; lng?: number }> {
  const c = await currentPosition();
  return c ? { lat: c.lat, lng: c.lng } : {};
}
