# Quorker Conector

Plugin de WordPress que publica en el sitio de un coworking lo que carga en [Quorker](https://quorker.com): sus espacios, sus planes y sus eventos.

**[Descargar la última versión](https://github.com/aurelmediadev/quorker-connector/releases/latest/download/quorker-connector.zip)**

## Qué hace

- **Espacios:** una página por espacio con "página pública" en Quorker (tipo de contenido `acw_space`, en `/espacios/`), con fotos, capacidad, sede, precios por hora y por duración, comodidades y el botón de reservar. Las categorías de Quorker son términos de `acw_space_category`.
- **Planes:** `[quorker_planes]` muestra los planes de membresía (y, con `packs="si"`, los packs de horas).
- **Eventos:** una página por evento publicado (`acw_event`, en `/eventos/`) con las entradas y el formulario de inscripción, que cobra con Mercado Pago si el evento es pago.
- **Botones:** "Reservar" y "Quiero este plan" llevan, según los ajustes, al portal de clientes de Quorker, a un chat de WhatsApp con el mensaje armado o a otra dirección.
- **Al día solo:** cuando algo cambia en Quorker, Quorker le avisa al sitio (aviso firmado con la clave del conector) y el plugin vuelve a leer. Además revisa cada hora.
- **Sin editar desde WordPress:** las páginas se ven en el admin, pero la fuente es Quorker.

## Shortcodes

| Shortcode | Qué muestra |
| --- | --- |
| `[quorker_espacios categoria="salas" cantidad="12" columnas="3"]` | Tarjetas de los espacios |
| `[quorker_reservar espacio="sala-norte" texto="Reservar"]` | El botón de reservar (en la página del espacio no hace falta `espacio`) |
| `[quorker_planes packs="si" columnas="3"]` | Los planes y los packs de horas |
| `[quorker_eventos cantidad="6"]` | Los próximos eventos |
| `[quorker_evento_inscripcion evento="charla"]` | El formulario de inscripción (en la página del evento no hace falta `evento`) |

Los shortcodes de la 0.2 (`[aurel_eventos]`, `[aurel_evento_inscripcion]`) siguen funcionando.

## Elementor y JetEngine

Cada página guarda sus datos en campos `acw_*` (fuente "Meta Data" del Dynamic Field de JetEngine). Para diseñarlas con plantillas de Elementor (individual y archivo), apagá "Contenido automático" en Ajustes → Quorker.

- **Espacios:** `acw_category`, `acw_capacity_label`, `acw_location`, `acw_address`, `acw_price_label`, `acw_rates_table`, `acw_amenities`, `acw_amenities_list`, `acw_gallery` (ids de adjuntos), `acw_book_url`.
- **Eventos:** `acw_date_label`, `acw_time_label`, `acw_place`, `acw_address`, `acw_price_from_label`, `acw_seats_label`, `acw_tickets_table`, `acw_register_url`, entre otros.

## Instalar

1. Bajá el zip de la última versión (link de arriba) y subilo en WordPress → Plugins → Añadir nuevo → Subir plugin. Activalo.
2. En Quorker → **Sitio web**: cargá la dirección del sitio y generá la clave del conector.
3. En WordPress → Ajustes → **Quorker**: pegá la dirección del coworking y la clave, y elegí a dónde llevan los botones.
4. Probá: "Probar la conexión" en Quorker y "Sincronizar ahora" en WordPress.

Las versiones nuevas aparecen en WordPress → Plugins como cualquier actualización.

## Publicar una versión

1. Subí la versión en `quorker-connector.php` (encabezado `Version` y constante `ACWC_VERSION`).
2. Commit, y una etiqueta con la misma versión:

   ```bash
   git tag v0.3.1
   git push origin main --tags
   ```

3. El workflow `Release` revisa la sintaxis, controla que la etiqueta coincida con la versión, arma `quorker-connector.zip` y publica la release. Los sitios la ven en unas horas (o al instante con "Buscar actualizaciones").

## Probar en local

El entorno de prueba (WordPress conectado al coworking demo) está en el repo del SaaS: `docker/wordpress/`. Espera este repo clonado al lado, en `../quorker-connector`.
