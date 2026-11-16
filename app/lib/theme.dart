import 'package:flutter/material.dart';

/// Colores de Liveo. La interfaz usa solo [accent] y [live]; el resto es
/// para el logo y la presentación inicial.
class Pal {
  static const accent = Color(0xff86d957);
  static const live = Color(0xffe5484d);

  static const lime = Color(0xffb6ff5c);
  static const cyan = Color(0xff22d3ee);
  static const violet = Color(0xff8b5cf6);
  static const pink = Color(0xfff43f5e);
  static const orange = Color(0xfffb923c);
  static const ink = Color(0xff07210f);

  static const brand = LinearGradient(colors: [lime, cyan]);
  static const hot = LinearGradient(colors: [pink, orange]);
}
