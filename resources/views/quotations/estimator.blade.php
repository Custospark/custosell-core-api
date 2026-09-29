<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Custosell Investment Estimator</title>
  <style>
    * { box-sizing: border-box; }
    body { font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; margin: 0; background: #f1f5f9; color: #1f2937; }
    .wrap { max-width: 960px; margin: 0 auto; padding: 24px 16px 48px; }
    .brand { display: flex; align-items: center; gap: 10px; margin-bottom: 4px; }
    .brand h1 { font-size: 20px; color: #1d4ed8; margin: 0; }
    .sub { color: #6b7280; font-size: 13px; margin: 0 0 16px 0; }
    .card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px; margin-bottom: 16px; }
    .card h2 { font-size: 14px; margin: 0 0 10px 0; }
    .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; }
    label { font-size: 12px; font-weight: 600; color: #475569; display: block; margin-bottom: 4px; }
    select, input { width: 100%; padding: 9px 10px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 14px; }
    table { width: 100%; border-collapse: collapse; font-size: 13px; }
    th { text-align: left; font-size: 11px; text-transform: uppercase; color: #64748b; padding: 6px 8px; border-bottom: 2px solid #e2e8f0; }
    td { padding: 7px 8px; border-bottom: 1px solid #f1f5f9; }
    td.num, th.num { text-align: right; white-space: nowrap; }
    tr.grand td { font-weight: bold; background: #eff6ff; font-size: 15px; }
    .btn { display: inline-block; background: #1d4ed8; color: #fff; border: 0; border-radius: 8px; padding: 11px 22px; font-size: 14px; font-weight: 600; cursor: pointer; }
    .btn:hover { background: #1e40af; }
    .muted { color: #6b7280; font-size: 12px; }
    .scroll-x { overflow-x: auto; }
  </style>
</head>
<body>
<div class="wrap">
  <div class="brand"><h1>Custosell Investment Estimator</h1></div>
  <p class="sub">Pick a package, scale it to your tills, staff and branches, then download a branded PDF budget in UGX with USD approximations.</p>

  <div class="card">
    <h2>1. Package &amp; scale</h2>
    <div class="grid">
      <div>
        <label for="plan">Package (plan tier)</label>
        <select id="plan">
          @foreach($packages as $pkg)
            <option value="{{ $pkg['slug'] }}">{{ $pkg['name'] }} - ${{ number_format($pkg['price_monthly_usd'], 2) }}/mo</option>
          @endforeach
        </select>
      </div>
      <div>
        <label for="billing">Billing</label>
        <select id="billing"><option value="monthly">Monthly</option><option value="yearly">Yearly</option></select>
      </div>
      <div><label for="tills">Tills</label><input id="tills" type="number" min="1" max="500" value="1"></div>
      <div><label for="staff">Staff</label><input id="staff" type="number" min="1" max="5000" value="3"></div>
      <div><label for="branches">Branches</label><input id="branches" type="number" min="1" max="100" value="1"></div>
      <div><label for="customer_name">Business name (for the PDF)</label><input id="customer_name" type="text" maxlength="120" placeholder="e.g. Divine Mercy Restaurant"></div>
    </div>
  </div>

  <div class="card">
    <h2>2. Extra hardware (optional, on top of the package kit)</h2>
    <div class="scroll-x">
    <table>
      <tr><th>Item</th><th class="num">UGX</th><th class="num">Qty</th></tr>
      @foreach($items as $item)
        <tr>
          <td>{{ $item['name'] }} <span class="muted">({{ $item['category'] }})</span><br><span class="muted">{{ $item['specs'] }}</span></td>
          <td class="num">{{ number_format($item['price_ugx'], 0) }}</td>
          <td class="num"><input type="number" min="0" max="10000" value="0" data-code="{{ $item['code'] }}" style="width:70px;" class="extra-qty"></td>
        </tr>
      @endforeach
    </table>
    </div>
  </div>

  <div class="card">
    <h2>3. Adjustments, extras &amp; your details</h2>
    <div class="grid">
      <div><label for="discount">Discount % (hardware)</label><input id="discount" type="number" min="0" max="100" value="0"></div>
      <div><label for="vat">VAT % (hardware + onboarding)</label><input id="vat" type="number" min="0" max="100" value="0"></div>
      <div><label for="rep_name">Sales rep name</label><input id="rep_name" type="text" maxlength="120" placeholder="e.g. OPIYO OSCAR"></div>
      <div><label for="rep_phone">Sales rep phone</label><input id="rep_phone" type="text" maxlength="32" placeholder="e.g. +2567..."></div>
    </div>
    <div style="margin-top:12px;">
      <label>Extra costs (travel, levies, furniture…)</label>
      <div id="custom-lines"></div>
      <button class="btn" type="button" id="add-line" style="background:#fff;color:#1d4ed8;border:1px solid #bfdbfe;margin-top:6px;">+ Add cost line</button>
    </div>
    <div style="margin-top:12px;">
      <label>Additional details (dates, references, notes…)</label>
      <div id="custom-fields"></div>
      <button class="btn" type="button" id="add-field" style="background:#fff;color:#1d4ed8;border:1px solid #bfdbfe;margin-top:6px;">+ Add detail</button>
    </div>
  </div>

  <div class="card">
    <h2>4. Your investment total</h2>
    <div id="totals"><p class="muted">Calculating…</p></div>
    <p class="muted" id="rate-note"></p>
    <form method="POST" action="/quotations/download" style="margin-top:12px;">
      @csrf
      <input type="hidden" name="plan" id="f-plan">
      <input type="hidden" name="billing" id="f-billing">
      <input type="hidden" name="customer_name" id="f-customer">
      <input type="hidden" name="discount_percent" id="f-discount">
      <input type="hidden" name="vat_percent" id="f-vat">
      <input type="hidden" name="rep_name" id="f-rep-name">
      <input type="hidden" name="rep_phone" id="f-rep-phone">
      <div id="f-drivers"></div>
      <div id="f-items"></div>
      <div id="f-custom"></div>
      <button class="btn" type="submit">Download PDF budget</button>
    </form>
  </div>
</div>
<script>
(function () {
  const $ = (id) => document.getElementById(id);
  function collectRepeater(containerId, keys) {
    const out = [];
    document.querySelectorAll(`#${containerId} [data-row]`).forEach((row) => {
      const entry = {};
      let filled = false;
      keys.forEach((k) => {
        const el = row.querySelector(`[data-k="${k}"]`);
        const v = (el.value || '').trim();
        entry[k] = v;
        if (v !== '' && v !== '0') filled = true;
      });
      if (filled) out.push(entry);
    });
    return out;
  }
  function addRepeaterRow(containerId, fields) {
    const row = document.createElement('div');
    row.dataset.row = '1';
    row.style.cssText = 'display:flex;gap:6px;margin-bottom:6px;';
    row.innerHTML = fields.map((f) =>
      `<input data-k="${f.k}" type="${f.type}" placeholder="${f.ph}" maxlength="${f.max}" style="flex:${f.flex};">`).join('') +
      `<button type="button" data-x="1" class="btn" style="background:#fff;color:#64748b;border:1px solid #cbd5e1;padding:9px 12px;">&times;</button>`;
    row.querySelector('[data-x]').addEventListener('click', () => { row.remove(); refresh(); });
    $(containerId).appendChild(row);
  }
  $('add-line').addEventListener('click', () => addRepeaterRow('custom-lines', [
    { k: 'label', type: 'text', ph: 'e.g. Upcountry travel', max: 120, flex: 2 },
    { k: 'amount_ugx', type: 'number', ph: 'UGX', max: 20, flex: 1 },
  ]));
  $('add-field').addEventListener('click', () => addRepeaterRow('custom-fields', [
    { k: 'label', type: 'text', ph: 'e.g. Valid until', max: 80, flex: 1 },
    { k: 'value', type: 'text', ph: 'e.g. 30 Oct 2026', max: 500, flex: 2 },
  ]));
  async function refresh() {
    const items = [];
    document.querySelectorAll('.extra-qty').forEach((el) => {
      const qty = parseInt(el.value || '0', 10);
      if (qty > 0) items.push({ code: el.dataset.code, qty });
    });
    const customLines = collectRepeater('custom-lines', ['label', 'amount_ugx'])
      .map((r) => ({ label: r.label, amount_ugx: parseFloat(r.amount_ugx) || 0 }))
      .filter((r) => r.label && r.amount_ugx > 0);
    const customFields = collectRepeater('custom-fields', ['label', 'value'])
      .filter((r) => r.label && r.value);
    const payload = {
      plan: $('plan').value,
      billing: $('billing').value,
      drivers: {
        tills: Math.max(1, parseInt($('tills').value || '1', 10)),
        staff: Math.max(1, parseInt($('staff').value || '1', 10)),
        branches: Math.max(1, parseInt($('branches').value || '1', 10)),
      },
      items,
      custom_lines: customLines,
      custom_fields: customFields,
      discount_percent: Math.min(100, Math.max(0, parseFloat($('discount').value || '0'))),
      vat_percent: Math.min(100, Math.max(0, parseFloat($('vat').value || '0'))),
      rep_name: $('rep_name').value.trim(),
      rep_phone: $('rep_phone').value.trim(),
    };
    const res = await fetch('/api/v1/quotations/estimate', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify(payload),
    });
    if (!res.ok) { $('totals').innerHTML = '<p class="muted">Could not calculate - check your inputs.</p>'; return; }
    const d = (await res.json()).data;
    const fmt = (n) => Number(n).toLocaleString('en-US', { maximumFractionDigits: 0 });
    let rows = d.hardware_lines.map((l) =>
      `<tr><td>${l.name} &times; ${l.qty}<br><span class="muted">${l.specs || ''}</span></td><td class="num">${fmt(l.line_total_ugx)}</td></tr>`).join('');
    rows += `<tr><td>Software first year + onboarding</td><td class="num">${fmt(d.subscription_first_year_ugx + d.onboarding_ugx)}</td></tr>`;
    rows += `<tr><td>Annual maintenance</td><td class="num">${fmt(d.maintenance_annual_ugx)}</td></tr>`;
    (d.custom_lines || []).forEach((c) => { rows += `<tr><td>${c.label}</td><td class="num">${fmt(c.amount_ugx)}</td></tr>`; });
    if (d.discount_ugx > 0) rows += `<tr><td>Discount (${d.discount_percent}%)</td><td class="num">-${fmt(d.discount_ugx)}</td></tr>`;
    if (d.vat_ugx > 0) rows += `<tr><td>VAT (${d.vat_percent}%)</td><td class="num">${fmt(d.vat_ugx)}</td></tr>`;
    rows += `<tr class="grand"><td>GRAND TOTAL</td><td class="num">${fmt(d.grand_total_ugx)} UGX (~$${Number(d.grand_total_usd).toLocaleString('en-US', { maximumFractionDigits: 2 })})</td></tr>`;
    $('totals').innerHTML = `<div class="scroll-x"><table>${rows}</table></div>`;
    $('rate-note').textContent = d.usd_rate_note;
    $('f-plan').value = payload.plan;
    $('f-billing').value = payload.billing;
    $('f-customer').value = $('customer_name').value;
    $('f-discount').value = payload.discount_percent;
    $('f-vat').value = payload.vat_percent;
    $('f-rep-name').value = payload.rep_name;
    $('f-rep-phone').value = payload.rep_phone;
    $('f-drivers').innerHTML =
      `<input type="hidden" name="drivers[tills]" value="${payload.drivers.tills}">` +
      `<input type="hidden" name="drivers[staff]" value="${payload.drivers.staff}">` +
      `<input type="hidden" name="drivers[branches]" value="${payload.drivers.branches}">`;
    $('f-items').innerHTML = items.map((it, i) =>
      `<input type="hidden" name="items[${i}][code]" value="${it.code}"><input type="hidden" name="items[${i}][qty]" value="${it.qty}">`).join('');
    $('f-custom').innerHTML = customLines.map((it, i) =>
      `<input type="hidden" name="custom_lines[${i}][label]" value="${it.label.replace(/"/g, '&quot;')}"><input type="hidden" name="custom_lines[${i}][amount_ugx]" value="${it.amount_ugx}">`).join('') +
      customFields.map((it, i) =>
      `<input type="hidden" name="custom_fields[${i}][label]" value="${it.label.replace(/"/g, '&quot;')}"><input type="hidden" name="custom_fields[${i}][value]" value="${it.value.replace(/"/g, '&quot;')}">`).join('');
  }
  document.querySelector('.wrap').addEventListener('input', refresh);
  document.querySelector('.wrap').addEventListener('change', refresh);
  refresh();
})();
</script>
</body>
</html>
