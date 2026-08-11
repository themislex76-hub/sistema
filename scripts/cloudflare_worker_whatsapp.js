// Puente para el webhook de WhatsApp — recibe las peticiones de Meta y se
// las reenvía a api/whatsapp_relay.php del sistema, porque el hosting
// compartido bloquea las peticiones POST que llegan directo de Meta.
// Ver docs/DEPLOY_CPANEL.md, sección "Puente con Cloudflare Workers", para
// cómo desplegar esto y qué variables (env) configurar.

export default {
  async fetch(request, env) {
    const url = new URL(request.url);

    // Verificación del webhook (Meta la llama una sola vez al configurarlo).
    if (request.method === 'GET') {
      const modo = url.searchParams.get('hub.mode');
      const token = url.searchParams.get('hub.verify_token');
      const challenge = url.searchParams.get('hub.challenge');
      if (modo === 'subscribe' && token === env.VERIFY_TOKEN) {
        return new Response(challenge || '', { status: 200 });
      }
      return new Response('Forbidden', { status: 403 });
    }

    if (request.method !== 'POST') {
      return new Response('Method not allowed', { status: 405 });
    }

    const raw = await request.text();

    // Verifica que el mensaje venga realmente de Meta (misma firma que
    // usaría whatsapp_webhook.php).
    const firmaRecibida = request.headers.get('x-hub-signature-256') || '';
    const firmaEsperada = 'sha256=' + (await hmacSha256Hex(env.APP_SECRET, raw));
    if (firmaRecibida !== firmaEsperada) {
      return new Response('Forbidden', { status: 403 });
    }

    let data;
    try {
      data = JSON.parse(raw);
    } catch (e) {
      return new Response('Bad request', { status: 400 });
    }

    const mensajes = [];
    for (const entry of data.entry || []) {
      for (const change of entry.changes || []) {
        const value = change.value || {};
        const nombre = value.contacts && value.contacts[0] && value.contacts[0].profile
          ? value.contacts[0].profile.name
          : null;
        for (const msg of value.messages || []) {
          mensajes.push({
            id: msg.id || '',
            telefono: msg.from || '',
            tipo: msg.type || '',
            texto: msg.type === 'text' && msg.text ? (msg.text.body || '') : '',
            nombre,
          });
        }
      }
    }

    if (mensajes.length > 0) {
      await fetch(env.SISTEMA_RELAY_URL, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-Relay-Key': env.RELAY_KEY,
        },
        body: JSON.stringify({ mensajes }),
      });
    }

    return new Response('EVENT_RECEIVED', { status: 200 });
  },
};

async function hmacSha256Hex(secret, message) {
  const enc = new TextEncoder();
  const key = await crypto.subtle.importKey(
    'raw',
    enc.encode(secret),
    { name: 'HMAC', hash: 'SHA-256' },
    false,
    ['sign']
  );
  const firma = await crypto.subtle.sign('HMAC', key, enc.encode(message));
  return [...new Uint8Array(firma)].map((b) => b.toString(16).padStart(2, '0')).join('');
}
