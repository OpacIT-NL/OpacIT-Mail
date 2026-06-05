/**
 * X2Mail Setup Wizard — Mail server configuration (single domain).
 *
 * SSO uses the email domain from the user's NC profile to resolve the
 * IMAP/SMTP config. One domain config is sufficient for all SSO users
 * sharing the same mail server.
 */

document.addEventListener('DOMContentLoaded', () => {
	const wizard = document.getElementById('x2mail-wizard');
	if (!wizard) return;

	const el = id => document.getElementById(id);
	const baseUrl = OC.generateUrl('/apps/x2mail/setup');

	let wizardData = { domains: {}, oidc: {} };

	// ─── Helpers ─────────────────────────────────────────

	function normSsl(v) {
		v = (v || '').toLowerCase();
		if (v === 'ssl' || v === 'ssl/tls') return 'ssl';
		if (v === 'starttls' || v === 'tls') return 'starttls';
		return 'none';
	}

	function escHtml(s) {
		const d = document.createElement('span');
		d.textContent = s;
		return d.innerHTML;
	}

	function setStatus(msg, type) {
		const s = el('wiz-status-msg');
		s.textContent = msg;
		s.className = 'status-msg' + (type === 'ok' ? ' ok' : type === 'err' ? ' err' : '');
	}

	function getFormValues() {
		return {
			domain: el('wiz-domain').value.trim(),
			imap_host: el('wiz-imap-host').value.trim(),
			imap_port: parseInt(el('wiz-imap-port').value) || 143,
			imap_ssl: el('wiz-imap-ssl').value,
			smtp_host: el('wiz-smtp-host').value.trim(),
			smtp_port: parseInt(el('wiz-smtp-port').value) || 25,
			smtp_ssl: el('wiz-smtp-ssl').value,
			smtp_auth: el('wiz-smtp-auth').checked ? '1' : '0',
			auth_type: el('wiz-auth-type').value,
			sieve: el('wiz-sieve').checked ? '1' : '0',
			oidc_provider: el('wiz-oidc-provider').value,
		};
	}

	function updateDomainNote() {
		const note = el('wiz-domain-note');
		if (!note) return;

		const parts = [
			t('x2mail', 'This release branch uses one active domain configuration. Saving replaces any previously configured domain.'),
		];
		if (wizardData.multiple_domains_detected) {
			parts.push(t('x2mail', 'Multiple stored domains were detected. Saving keeps only the domain shown below.'));
			note.className = 'setup-note warning';
		} else {
			note.className = 'setup-note';
		}
		note.textContent = parts.join(' ');
	}

	// ─── OIDC field visibility ──────────────────────────

	function updateOidcVisibility() {
		const isOAuth = el('wiz-auth-type').value === 'oauth';
		el('wiz-oidc-provider').style.display = isOAuth ? '' : 'none';
		el('wiz-oidc-label').style.display = isOAuth ? '' : 'none';
	}

	el('wiz-auth-type').addEventListener('change', updateOidcVisibility);

	// ─── Populate form ──────────────────────────────────

	function populateForm(domain, cfg) {
		el('wiz-domain').value = domain || '';
		el('wiz-imap-host').value = cfg?.imap_host || '';
		el('wiz-imap-port').value = cfg?.imap_port || 143;
		el('wiz-imap-ssl').value = normSsl(cfg?.imap_ssl);
		el('wiz-smtp-host').value = cfg?.smtp_host || '';
		el('wiz-smtp-port').value = cfg?.smtp_port || 25;
		el('wiz-smtp-ssl').value = normSsl(cfg?.smtp_ssl);
		el('wiz-smtp-auth').checked = !!cfg?.smtp_auth;
		const authType = cfg?.auth_type || 'oauth';
		el('wiz-auth-type').value = authType;
		el('wiz-sieve').checked = !!cfg?.sieve;
		updateOidcVisibility();

		el('wiz-delete-btn').style.display = domain ? '' : 'none';

		// Clear previous results
		const results = el('wiz-preflight-results');
		results.style.display = 'none';
		results.textContent = '';
		setStatus('', '');
	}

	// ─── Load config ────────────────────────────────────

	function loadConfig() {
		fetch(baseUrl + '/config', {
			credentials: 'same-origin',
			headers: { 'requesttoken': OC.requestToken },
		})
		.then(r => {
			if (!r.ok) throw new Error('HTTP ' + r.status);
			return r.json();
		})
			.then(data => {
			wizardData = data;
			updateDomainNote();

			// Update OIDC provider dropdown
			if (data.oidc) {
				const provSel = el('wiz-oidc-provider');
				Array.from(provSel.options).forEach(opt => {
					if (opt.value === 'user_oidc') opt.disabled = !data.oidc.user_oidc;
					if (opt.value === 'oidc_login') opt.disabled = !data.oidc.oidc_login;
				});
				if (data.oidc.provider && data.oidc.provider !== 'none') {
					provSel.value = data.oidc.provider;
				}
			}

			const domains = Object.keys(data.domains || {});
			if (domains.length > 0) {
				populateForm(domains[0], data.domains[domains[0]]);
			} else {
				populateForm('', null);
				if (data.suggested_domain) {
					el('wiz-domain').value = data.suggested_domain;
					el('wiz-domain').placeholder = data.suggested_domain;
				}
			}
		})
		.catch(err => {
			console.error('Setup wizard: failed to load config', err);
			setStatus(t('x2mail', 'Failed to load configuration: {error}').replace('{error}', err.message), 'err');
		});
	}

	// ─── Preflight check ────────────────────────────────

	function buildCheckLine(type, text) {
		const icon = type === 'ok' ? '\u2713' : type === 'fail' ? '\u2717' : '\u26A0';
		const line = document.createElement('div');
		line.className = 'check-line check-' + type;
		line.textContent = icon + ' ' + text;
		return line;
	}

	el('wiz-preflight-btn').addEventListener('click', e => {
		e.preventDefault();
		const results = el('wiz-preflight-results');
		const vals = getFormValues();

		if (!vals.imap_host) {
			results.style.display = 'block';
			results.className = 'preflight-results error';
			results.textContent = t('x2mail', 'IMAP host is required');
			return;
		}

		results.style.display = 'block';
		results.className = 'preflight-results running';
		results.textContent = t('x2mail', 'Running checks...');

		fetch(baseUrl + '/preflight', {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'requesttoken': OC.requestToken,
				'Content-Type': 'application/x-www-form-urlencoded',
			},
			body: new URLSearchParams(vals),
		})
		.then(r => r.json())
		.then(data => {
			results.textContent = '';
			let hasError = false;

			// IMAP
			if (data.imap) {
				if (data.imap.connected) {
					const auth = data.imap.auth_methods?.length ? data.imap.auth_methods.join(', ') : 'no AUTH capability advertised';
					results.appendChild(buildCheckLine('ok',
						'IMAP  ' + vals.imap_host + ':' + vals.imap_port + ' (' + auth + ')'));
						if (data.imap.oauth_supported === false) {
							results.appendChild(buildCheckLine('fail',
								'IMAP server does not support OAUTHBEARER/XOAUTH2'));
							hasError = true;
						}
						if (data.imap.tls_warning) {
							results.appendChild(buildCheckLine('warn', data.imap.tls_warning));
						}
					} else {
						results.appendChild(buildCheckLine('fail',
							'IMAP  ' + vals.imap_host + ':' + vals.imap_port + ' \u2014 ' + (data.imap.error || 'connection failed')));
						hasError = true;
				}
			}

			// SMTP
			if (data.smtp) {
				if (data.smtp.connected) {
					const banner = data.smtp.banner ? data.smtp.banner.replace(/^220\s*/, '').substring(0, 50) : '';
					results.appendChild(buildCheckLine('ok',
						'SMTP  ' + (vals.smtp_host || vals.imap_host) + ':' + vals.smtp_port + (banner ? ' (' + banner + ')' : '')));
						if (data.smtp.auth_required && data.smtp.oauth_supported === false) {
							results.appendChild(buildCheckLine('fail',
								'SMTP server does not support OAUTHBEARER/XOAUTH2 for authenticated sending'));
							hasError = true;
						}
						if (data.smtp.tls_warning) {
							results.appendChild(buildCheckLine('warn', data.smtp.tls_warning));
						}
					} else {
						results.appendChild(buildCheckLine('fail',
							'SMTP  ' + (vals.smtp_host || vals.imap_host) + ':' + vals.smtp_port + ' \u2014 ' + (data.smtp.error || 'connection failed')));
						hasError = true;
				}
			}

			// OIDC
			if (data.oidc) {
				if (data.oidc.any_installed) {
					let info = data.oidc.provider || data.oidc.requested_provider || 'unknown';
					if (data.oidc.provider_fallback) {
						info += ', fallback';
					}
					if (data.oidc.store_login_token === true) {
						info += ', token_store=ok';
					} else if (data.oidc.store_login_token === false) {
						info += ', token_store=will set';
					}
					results.appendChild(buildCheckLine('ok', 'OIDC  ' + info));

					if (data.oidc.provider_configured === false) {
						results.appendChild(buildCheckLine('warn',
							'OIDC  No provider configured in user_oidc (occ user_oidc:provider)'));
					}
					if (data.oidc.session_is_oidc && data.oidc.session_has_token) {
						results.appendChild(buildCheckLine('ok', 'SSO   Active session with valid token'));
						const t = data.oidc.token;
						if (t) {
							const lines = [];
							if (t.email) lines.push('email=' + t.email);
							if (t.aud) lines.push('aud=' + (Array.isArray(t.aud) ? t.aud.join(',') : t.aud));
							if (t.expires_in != null) lines.push('expires=' + Math.round(t.expires_in / 60) + 'min');
							if (lines.length) {
								results.appendChild(buildCheckLine('ok', 'TOKEN ' + lines.join(', ')));
							}
							if (!t.email) {
								results.appendChild(buildCheckLine('warn', 'TOKEN Missing email claim — IMAP login may fail'));
							}
							if (t.aud && !JSON.stringify(t.aud).includes('dovecot')) {
								results.appendChild(buildCheckLine('warn', 'TOKEN No "dovecot" in audience — verify OIDC client mapper'));
							}
						}
					} else if (data.oidc.session_is_oidc) {
						results.appendChild(buildCheckLine('warn', 'SSO   OIDC session but no access token'));
					} else {
						results.appendChild(buildCheckLine('warn', 'SSO   Not logged in via OIDC (use SSO login to verify)'));
					}
				} else {
					results.appendChild(buildCheckLine('fail',
						'OIDC  No provider installed (need user_oidc or oidc_login)'));
					hasError = true;
				}
			}

			results.className = 'preflight-results ' + (hasError ? 'error' : 'success');
		})
		.catch(err => {
			results.className = 'preflight-results error';
			results.textContent = 'Request failed: ' + err.message;
		});
	});

	// ─── Save ───────────────────────────────────────────

	el('wiz-save-btn').addEventListener('click', e => {
		e.preventDefault();
		const vals = getFormValues();

		if (!vals.domain) { setStatus(t('x2mail', 'Domain is required'), 'err'); return; }
		if (!vals.imap_host) { setStatus(t('x2mail', 'IMAP host is required'), 'err'); return; }

		setStatus(t('x2mail', 'Saving...'), '');

		fetch(baseUrl + '/save', {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'requesttoken': OC.requestToken,
				'Content-Type': 'application/x-www-form-urlencoded',
			},
			body: new URLSearchParams(vals),
		})
		.then(r => r.json())
			.then(data => {
				if (data.status === 'success') {
					const message = data.cleanup_warnings?.length
						? (data.message || t('x2mail', 'Saved')) + ' ' + t('x2mail', 'Some previous domains could not be removed automatically.')
						: (data.message || t('x2mail', 'Saved'));
					setStatus(message, 'ok');
					loadConfig();
				} else {
					setStatus(data.message || t('x2mail', 'Save failed'), 'err');
				}
		})
		.catch(err => {
			setStatus('Error: ' + err.message, 'err');
		});
	});

	// ─── Delete ─────────────────────────────────────────

	el('wiz-delete-btn').addEventListener('click', e => {
		e.preventDefault();
		const domain = el('wiz-domain').value.trim();
		if (!domain || !confirm(t('x2mail', 'Delete domain "{domain}"?').replace('{domain}', domain))) {
			return;
		}

		setStatus(t('x2mail', 'Deleting...'), '');

		fetch(baseUrl + '/delete', {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'requesttoken': OC.requestToken,
				'Content-Type': 'application/x-www-form-urlencoded',
			},
			body: new URLSearchParams({ domain }),
		})
		.then(r => r.json())
		.then(data => {
			if (data.status === 'success') {
				setStatus(data.message || t('x2mail', 'Deleted'), 'ok');
				loadConfig();
			} else {
				setStatus(data.message || t('x2mail', 'Delete failed'), 'err');
			}
		})
		.catch(err => {
			setStatus('Error: ' + err.message, 'err');
		});
	});

	// ─── Init ───────────────────────────────────────────

	loadConfig();
});
