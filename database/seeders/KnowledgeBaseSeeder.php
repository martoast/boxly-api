<?php

namespace Database\Seeders;

use App\Models\KnowledgeArticle;
use Illuminate\Database\Seeder;

/**
 * Initial knowledge wiki for the AI assistant's business Q&A. Customer-facing
 * content only (no internal costs/margins). Idempotent (keyed on slug) so it's
 * safe to re-run; the team edits/expands these in the admin "Base de conocimiento".
 *
 *   php artisan db:seed --class=Database\\Seeders\\KnowledgeBaseSeeder
 */
class KnowledgeBaseSeeder extends Seeder
{
    private const ARTICLES = [
        [
            'section' => 'General',
            'sort_order' => 1,
            'title' => '¿Qué es Boxly?',
            'content' => <<<'MD'
Boxly es un servicio que te ayuda a comprar productos de tiendas de Estados Unidos y recibirlos en México, aunque la tienda no envíe a México y aunque no tengas tarjeta de Estados Unidos.

Hay dos formas principales:
- **Compra asistida:** nos mandas el link (o buscas el producto aquí) y **Boxly lo compra por ti** en Estados Unidos, lo importa y te lo entrega en México.
- **Tu casillero en EE. UU.:** te damos una dirección en Estados Unidos para que compres tú mismo; recibimos tus paquetes, los consolidamos y te los enviamos a México.

Es ideal tanto para compras personales como para **revendedores** que traen producto de Estados Unidos.
MD,
        ],
        [
            'section' => 'General',
            'sort_order' => 2,
            'title' => '¿Cómo funciona Boxly? (paso a paso)',
            'content' => <<<'MD'
1. **Encuentras el producto.** Búscalo aquí mismo o mándanos el link de la tienda de EE. UU.
2. **Creas una solicitud.** Agregas lo que quieres y mandas la solicitud. No pagas nada todavía.
3. **Te cotizamos.** Boxly revisa disponibilidad y te manda una cotización con el total: producto + comisión + envío + impuestos.
4. **Apruebas y pagas.** Solo si estás de acuerdo con la cotización, pagas.
5. **Compramos e importamos.** Boxly compra el producto, lo recibe en su bodega de EE. UU. y lo importa a México.
6. **Te lo entregamos** en tu domicilio en México.

En todo momento puedes ver el estado de tu solicitud en tu cuenta.
MD,
        ],
        [
            'section' => 'Precios',
            'sort_order' => 1,
            'title' => 'Precios y comisiones',
            'content' => <<<'MD'
El precio que ves en la tienda **no es el precio final**. Tu total se forma así:

- **Precio del producto** (lo que cuesta en la tienda de EE. UU.)
- **+ Comisión de Boxly (10%)**
- **+ Envío internacional** (de EE. UU. a México)
- **+ Impuestos** cuando aplican

**Pagas solo cuando apruebas la cotización.** Antes de pagar te mandamos el total desglosado para que sepas exactamente cuánto vas a pagar. Si no te convence, no hay ningún compromiso.
MD,
        ],
        [
            'section' => 'Precios',
            'sort_order' => 2,
            'title' => 'Impuestos (IVA)',
            'content' => <<<'MD'
A las importaciones se les aplica **IVA del 16%** sobre el valor declarado cuando este es de **$50 USD o más**. Para compras de menor valor normalmente no aplica.

El monto exacto de impuestos siempre viene incluido y desglosado en tu cotización, así que no hay sorpresas.
MD,
        ],
        [
            'section' => 'Envíos',
            'sort_order' => 1,
            'title' => 'Envíos y tiempos de entrega',
            'content' => <<<'MD'
Boxly entrega **a todo México**.

El tiempo depende de qué tan rápido llega el producto a nuestra bodega en EE. UU. y del envío a México. Una vez que el producto está en nuestra bodega, lo preparamos y lo enviamos a tu domicilio.

Si tienes una solicitud en curso, puedes ver su avance en tu cuenta, paso por paso.
MD,
        ],
        [
            'section' => 'Casillero',
            'sort_order' => 1,
            'title' => 'Tu casillero en Estados Unidos',
            'content' => <<<'MD'
Tu **casillero** es una dirección en Estados Unidos que Boxly te asigna. La usas como dirección de envío cuando compras en tiendas de EE. UU. por tu cuenta.

Nosotros recibimos tus paquetes ahí, los **consolidamos** (juntamos varios en un solo envío para que pagues menos) y te los mandamos a México.

Tu dirección de casillero específica aparece en tu cuenta de Boxly, en la sección de tu perfil.
MD,
        ],
        [
            'section' => 'Compras presenciales',
            'sort_order' => 1,
            'title' => 'Compras presenciales en San Diego',
            'content' => <<<'MD'
Además de comprar en línea, Boxly puede **comprar en persona** por ti en outlets y tiendas de la zona de San Diego (como Las Américas).

Nos dices qué necesitas, nuestro equipo lo compra en la tienda física y lo importamos a México igual que cualquier otra solicitud. Es útil para productos que no se consiguen en línea o cuando quieres aprovechar ofertas de tienda.
MD,
        ],
        [
            'section' => 'Pagos',
            'sort_order' => 1,
            'title' => 'Métodos de pago',
            'content' => <<<'MD'
Pagas en México y **solo después de aprobar tu cotización**. Te enviamos un enlace de pago seguro para completar tu compra.

No necesitas tarjeta de Estados Unidos ni VPN: Boxly se encarga de la compra en EE. UU. por ti.
MD,
        ],
        [
            'section' => 'General',
            'sort_order' => 3,
            'title' => '¿Necesito tarjeta de EE. UU. o VPN?',
            'content' => <<<'MD'
No. No necesitas tarjeta de Estados Unidos ni VPN. Esa es justo la ventaja de Boxly: **nosotros compramos por ti** en EE. UU. y tú pagas en México en pesos. Tú solo eliges el producto y apruebas la cotización.
MD,
        ],
    ];

    public function run(): void
    {
        foreach (self::ARTICLES as $a) {
            KnowledgeArticle::updateOrCreate(
                ['slug' => \Illuminate\Support\Str::slug($a['title'])],
                [
                    'title'        => $a['title'],
                    'section'      => $a['section'],
                    'content'      => $a['content'],
                    'sort_order'   => $a['sort_order'],
                    'is_published' => true,
                ],
            );
        }
    }
}
