import { Image } from 'expo-image';
import { View } from 'react-native';
import Svg, { Circle, Path } from 'react-native-svg';

import { Text } from './ui';

import { useBrand } from '@/lib/brand';
import { colors } from '@/theme';

/** Logo WISPCONTROL (components/brand/logo.blade.php) o el logo del WISP con marca blanca. */
export function Logo({ dark = true, size = 'md', tagline = true }: { dark?: boolean; size?: 'sm' | 'md' | 'lg'; tagline?: boolean }) {
  const { logoUrl } = useBrand();
  const icon = size === 'sm' ? 24 : size === 'lg' ? 44 : 32;
  const text = size === 'sm' ? 18 : size === 'lg' ? 30 : 22;

  if (logoUrl) {
    return <Image source={{ uri: logoUrl }} style={{ width: 170, height: icon + 8 }} contentFit="contain" />;
  }

  const fg = dark ? '#FFFFFF' : '#0B1B3A';
  return (
    <View style={{ flexDirection: 'row', alignItems: 'center', gap: 8 }}>
      <Svg width={icon} height={(icon * 40) / 48} viewBox="0 0 48 40" fill="none">
        <Path d="M4 15.5C9.6 9.6 16.5 6.5 24 6.5S38.4 9.6 44 15.5" stroke="#1A7FF5" strokeWidth={5} strokeLinecap="round" />
        <Path d="M11 22.5c3.6-3.7 8-5.6 13-5.6s9.4 1.9 13 5.6" stroke="#1A7FF5" strokeWidth={5} strokeLinecap="round" />
        <Path d="M18 29.5c1.7-1.7 3.7-2.6 6-2.6s4.3.9 6 2.6" stroke={fg} strokeWidth={5} strokeLinecap="round" />
        <Circle cx={24} cy={35.5} r={3.2} fill={fg} />
      </Svg>
      <View>
        <Text weight="extrabold" style={{ fontSize: text, letterSpacing: -0.5, color: dark ? colors.white : colors.navy[900] }}>
          WISP<Text weight="extrabold" style={{ fontSize: text, letterSpacing: -0.5, color: colors.brand[500] }}>CONTROL</Text>
        </Text>
        {tagline ? (
          <Text weight="medium" style={{ fontSize: 10, textAlign: 'right', color: dark ? colors.navy[200] : colors.slate[500] }}>
            Tu WISP bajo control
          </Text>
        ) : null}
      </View>
    </View>
  );
}
