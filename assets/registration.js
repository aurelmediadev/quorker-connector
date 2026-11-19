/*
 * Formulario de inscripción de Quorker ([quorker_evento_inscripcion]). Lee el evento en vivo de la
 * API pública del coworking (entradas a la venta y lugares) y manda la inscripción directo allá. Si
 * hay que pagar, lleva a Mercado Pago; si no, a la página con las entradas.
 */
(function () {
  'use strict';

  function el(tag, attrs, children) {
    var node = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (key) {
      if (key === 'text') node.textContent = attrs[key];
      else if (key === 'hidden') node.hidden = !!attrs[key];
      else node.setAttribute(key, attrs[key]);
    });
    (children || []).forEach(function (child) { if (child) node.appendChild(child); });
    return node;
  }

  function money(cents, currency) {
    if (cents === 0) return 'Gratis';
    try {
      return new Intl.NumberFormat('es-AR', { style: 'currency', currency: currency || 'ARS', maximumFractionDigits: cents % 100 === 0 ? 0 : 2 }).format(cents / 100);
    } catch (e) {
      return '$ ' + (cents / 100).toLocaleString('es-AR');
    }
  }

  function field(label, input) {
    return el('label', { class: 'acwc-field' }, [el('span', { text: label }), input]);
  }

  function mount(root) {
    var api = root.getAttribute('data-api');
    var slug = root.getAttribute('data-event');
    var site = root.getAttribute('data-site') || '';
    var base = api + '/api/v1/public/events/' + encodeURIComponent(slug);

    fetch(base, { headers: { Accept: 'application/json' } })
      .then(function (res) { if (!res.ok) throw new Error('No encontramos el evento.'); return res.json(); })
      .then(function (event) { render(root, event, base, site); })
      .catch(function (err) { root.innerHTML = ''; root.appendChild(el('p', { class: 'acwc-muted', text: err.message })); });
  }

  function render(root, event, base, site) {
    root.innerHTML = '';
    if (!event.can_register) {
      root.appendChild(el('p', { class: 'acwc-notice', text: event.registration_message || 'La inscripción no está disponible.' }));
      return;
    }
    var types = (event.ticket_types || []).filter(function (t) { return t.on_sale && (t.left === null || t.left > 0); });
    var list = el('div', { class: 'acwc-attendees' });
    var totalEl = el('strong', { class: 'acwc-total' });
    var submit = el('button', { type: 'submit', class: 'acwc-button' });
    var add = el('button', { type: 'button', class: 'acwc-link', text: '+ Sumar otra persona' });
    var error = el('p', { class: 'acwc-error', hidden: true, role: 'alert' });
    var first = el('input', { name: 'first_name', required: 'required', maxlength: '100', autocomplete: 'given-name' });
    var last = el('input', { name: 'last_name', maxlength: '100', autocomplete: 'family-name' });
    var email = el('input', { name: 'email', type: 'email', required: 'required', maxlength: '191', autocomplete: 'email' });
    var phone = el('input', { name: 'phone', maxlength: '50', autocomplete: 'tel' });
    var consent = el('input', { type: 'checkbox', name: 'marketing_consent' });
    var trap = el('input', { name: 'website', tabindex: '-1', autocomplete: 'off' });
    var coupon = el('input', { name: 'coupon', maxlength: '40', autocomplete: 'off' });

    function option(t) {
      return t.name + ' · ' + money(t.price_cents) + (t.promo_label ? ' · ' + t.promo_label : '') + (t.members_only ? ' (socios)' : '') + (t.left !== null ? ' · quedan ' + t.left : '');
    }

    function total() {
      // Con las promos por cantidad (2x1, 50 % en la segunda): cada N-ésima entrada de su tipo.
      var sum = 0;
      var seen = {};
      list.querySelectorAll('select').forEach(function (select) {
        var type = types.filter(function (t) { return String(t.id) === select.value; })[0];
        if (!type) return;
        seen[type.id] = (seen[type.id] || 0) + 1;
        var promo = type.promo && seen[type.id] % type.promo.every === 0;
        sum += promo ? Math.round(type.price_cents * (100 - type.promo.percent) / 100) : type.price_cents;
      });
      totalEl.textContent = 'Total: ' + money(sum);
      submit.textContent = sum > 0 ? 'Inscribirme y pagar' : 'Inscribirme';
      add.hidden = list.children.length >= (event.max_tickets || 10);
    }

    function row(isFirst) {
      var select = el('select', { 'aria-label': 'Tipo de entrada' }, types.map(function (t) { return el('option', { value: String(t.id), text: option(t) }); }));
      select.addEventListener('change', total);
      var div = el('div', { class: 'acwc-attendee' }, [select]);
      if (isFirst) {
        div.appendChild(el('span', { class: 'acwc-muted', text: 'Tu entrada' }));
      } else {
        var name = el('input', { placeholder: 'Nombre y apellido', required: 'required', maxlength: '191', 'data-role': 'name' });
        var mail = el('input', { type: 'email', placeholder: 'Su email (opcional)', maxlength: '191', 'data-role': 'email' });
        var remove = el('button', { type: 'button', class: 'acwc-link', text: 'Quitar' });
        remove.addEventListener('click', function () { div.remove(); total(); });
        div.appendChild(name);
        div.appendChild(mail);
        div.appendChild(remove);
      }
      list.appendChild(div);
      total();
    }

    var form = el('form', { class: 'acwc-form', novalidate: 'novalidate' }, [
      el('h3', { text: 'Inscribite' }),
      list,
      add,
      el('div', { class: 'acwc-grid' }, [field('Nombre', first), field('Apellido', last), field('Email', email), field('Teléfono (opcional)', phone)]),
      el('div', { class: 'acwc-trap', 'aria-hidden': 'true' }, [trap]),
      field('¿Tenés un cupón? (opcional)', coupon),
      el('label', { class: 'acwc-check' }, [consent, el('span', { text: ' Quiero recibir novedades' + (site ? ' de ' + site : '') })]),
      error,
      el('div', { class: 'acwc-actions' }, [submit, totalEl]),
      el('p', { class: 'acwc-muted', text: 'Te mandamos las entradas por email.' + (event.price_from_cents > 0 ? ' Se paga con Mercado Pago.' : '') }),
    ]);
    add.addEventListener('click', function () { row(false); });
    row(true);

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      error.hidden = true;
      submit.disabled = true;
      var tickets = Array.prototype.map.call(list.children, function (div) {
        var name = div.querySelector('[data-role=name]');
        var mail = div.querySelector('[data-role=email]');
        return { ticket_type_id: Number(div.querySelector('select').value), name: name ? name.value : null, email: mail ? mail.value : null };
      });
      fetch(base + '/registrations', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({
          first_name: first.value, last_name: last.value, email: email.value, phone: phone.value, website: trap.value,
          marketing_consent: consent.checked, tickets: tickets, coupon: coupon.value.trim() || null,
        }),
      })
        .then(function (res) {
          return res.json().catch(function () { return {}; }).then(function (json) {
            if (!res.ok) {
              var first = json.errors ? Object.keys(json.errors).map(function (k) { return [].concat(json.errors[k])[0]; })[0] : null;
              throw new Error(first || json.message || 'No se pudo completar la inscripción.');
            }
            return json;
          });
        })
        .then(function (json) {
          if (json.checkout_url || json.registration_url) {
            window.location.href = json.checkout_url || json.registration_url;
          } else {
            form.replaceWith(el('p', { class: 'acwc-notice', text: '¡Gracias! Te mandamos las entradas por email.' }));
          }
        })
        .catch(function (err) {
          error.textContent = err.message;
          error.hidden = false;
          submit.disabled = false;
        });
    });

    root.appendChild(form);
  }

  function boot() {
    document.querySelectorAll('.acwc-register[data-event]').forEach(mount);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
