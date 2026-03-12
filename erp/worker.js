// Cloudflare Worker — AI Gateway for ERP
// Браузер → Worker → Anthropic Claude API
// API-ключ хранится в env.ANTHROPIC_API_KEY (Cloudflare Settings → Secrets)

export default {
  async fetch(request, env) {
    // CORS preflight
    if (request.method === 'OPTIONS') {
      return new Response(null, { status: 204, headers: corsHeaders() });
    }
    if (request.method !== 'POST') {
      return new Response('Method not allowed', { status: 405, headers: corsHeaders() });
    }

    // Auth
    const auth = request.headers.get('X-Proxy-Key');
    if (auth !== (env.PROXY_KEY || 'BILLIARDER_ERP_2026')) {
      return new Response('Unauthorized', { status: 401, headers: corsHeaders() });
    }

    const apiKey = env.ANTHROPIC_API_KEY;
    if (!apiKey) {
      return jsonResponse({ error: 'ANTHROPIC_API_KEY not configured' }, 500);
    }

    try {
      const body = await request.json();
      const messages = body.messages || [];
      const model = body.model || 'claude-3-5-haiku-20241022';

      // Claude API: system prompt отдельно от messages
      let system = '';
      const chatMessages = [];
      for (const m of messages) {
        if (m.role === 'system') {
          system += (system ? '\n' : '') + m.content;
        } else {
          chatMessages.push({ role: m.role, content: m.content });
        }
      }

      // Anthropic Messages API
      const anthropicBody = {
        model,
        max_tokens: 4096,
        messages: chatMessages,
      };
      if (system) anthropicBody.system = system;

      const resp = await fetch('https://api.anthropic.com/v1/messages', {
        method: 'POST',
        headers: {
          'x-api-key': apiKey,
          'anthropic-version': '2023-06-01',
          'content-type': 'application/json',
        },
        body: JSON.stringify(anthropicBody),
      });

      const data = await resp.json();

      if (!resp.ok) {
        return jsonResponse({ error: data.error?.message || `Anthropic HTTP ${resp.status}`, raw: data }, resp.status);
      }

      // Extract text from content blocks
      const content = (data.content || [])
        .filter(b => b.type === 'text')
        .map(b => b.text)
        .join('');
      const usage = data.usage || {};

      return jsonResponse({ ok: true, content, usage });
    } catch (e) {
      return jsonResponse({ error: e.message }, 500);
    }
  }
};

function jsonResponse(obj, status = 200) {
  return new Response(JSON.stringify(obj), {
    status,
    headers: { 'Content-Type': 'application/json', ...corsHeaders() },
  });
}

function corsHeaders() {
  return {
    'Access-Control-Allow-Origin': '*',
    'Access-Control-Allow-Methods': 'POST, OPTIONS',
    'Access-Control-Allow-Headers': 'Content-Type, X-Proxy-Key',
  };
}
