(() => {
	'use strict';
	var e,
		s = {
			493: (e, s, t) => {
				const i = window.wp.domReady;
				var a = t.n(i);
				const n = window.wp.element,
					r = window.wp.components,
					l = window.wp.i18n,
					d = window.wp.apiFetch;
				var o = t.n(d);
				const c = window.ReactJSXRuntime,
					p = ({
						section: e,
						enabled: s,
						isSaving: t,
						onChange: i,
					}) =>
						(0, c.jsxs)(r.Card, {
							className: 'ai-experiments-settings-app__card',
							children: [
								(0, c.jsx)(r.CardBody, {
									children: (0, c.jsxs)('div', {
										className:
											'ai-experiments-settings-app__card-header',
										children: [
											(0, c.jsxs)('div', {
												children: [
													(0, c.jsx)('h2', {
														className:
															'ai-experiments-settings-app__card-title',
														children:
															e.title ||
															(0, l.__)(
																'Experimental Features',
																'ai'
															),
													}),
													e.description
														? (0, c.jsx)('p', {
																className:
																	'ai-experiments-settings-app__card-description',
																children:
																	e.description,
															})
														: null,
												],
											}),
											(0, c.jsx)('div', {
												className:
													'ai-experiments-settings-app__card-action',
												children:
													t &&
													(0, c.jsx)(r.Spinner, {}),
											}),
										],
									}),
								}),
								(0, c.jsx)(r.CardDivider, {}),
								(0, c.jsxs)(r.CardBody, {
									children: [
										(0, c.jsx)(r.ToggleControl, {
											label: (0, l.__)(
												'Enable Experimental Features',
												'ai'
											),
											checked: s,
											help:
												e.description ||
												(0, l.__)(
													'Allow experimental AI features to run on this site.',
													'ai'
												),
											onChange: i,
											disabled: t,
											__nextHasNoMarginBottom: !0,
										}),
										(0, c.jsx)('p', {
											className:
												'ai-experiments-settings-app__helper',
											children: (0, l.__)(
												'Toggling this switch enables or disables all experimental AI capabilities provided by this plugin.',
												'ai'
											),
										}),
									],
								}),
							],
						}),
					g = ({
						section: e,
						masterEnabled: s,
						isSaving: t,
						onToggle: i,
					}) => {
						const a = !s || t,
							n = null !== e.featureId;
						return (0, c.jsx)(
							r.Card,
							{
								className: 'ai-experiments-settings-app__card',
								children: (0, c.jsx)(r.CardBody, {
									children: (0, c.jsxs)('div', {
										className:
											'ai-experiments-settings-app__card-header',
										children: [
											(0, c.jsxs)('div', {
												children: [
													(0, c.jsx)('h3', {
														className:
															'ai-experiments-settings-app__card-title',
														children: e.title,
													}),
													e.description
														? (0, c.jsx)('p', {
																className:
																	'ai-experiments-settings-app__card-description',
																children:
																	e.description,
															})
														: null,
												],
											}),
											n &&
												(0, c.jsx)('div', {
													className:
														'ai-experiments-settings-app__card-action',
													children: (0, c.jsx)(
														r.ToggleControl,
														{
															checked: e.enabled,
															disabled: a,
															onChange: (s) =>
																i(
																	e.featureId,
																	s
																),
															__nextHasNoMarginBottom:
																!0,
														}
													),
												}),
										],
									}),
								}),
							},
							e.id
						);
					},
					u = ({ settings: e }) => {
						const [s, t] = (0, n.useState)(e.toggle.enabled),
							[i, a] = (0, n.useState)(e.featureToggles.toggles),
							[d, u] = (0, n.useState)(!1),
							[m, x] = (0, n.useState)(null),
							h = (0, n.useMemo)(() => {
								var s;
								return null !==
									(s = e.sections.find(
										(e) => 'ai-experiments-toggle' === e.id
									)) && void 0 !== s
									? s
									: e.sections[0];
							}, [e.sections]),
							_ = (0, n.useMemo)(
								() =>
									e.sections
										.filter((e) => e.id !== h?.id)
										.map((e) => {
											const s =
												e.featureId && e.featureId in i
													? i[e.featureId]
													: e.enabled;
											return { ...e, enabled: s };
										}),
								[e.sections, h, i]
							),
							v = (0, n.useCallback)(
								(i) => {
									if (i === s) return;
									const a = s;
									(t(i),
										u(!0),
										x(null),
										o()({
											path: '/wp/v2/settings',
											method: 'POST',
											data: { [e.toggle.restField]: i },
										})
											.then(() => {
												x({
													status: 'success',
													message: (0, l.__)(
														'Experimental features setting updated.',
														'ai'
													),
												});
											})
											.catch(() => {
												(t(a),
													x({
														status: 'error',
														message: (0, l.__)(
															'Saving failed. Please try again.',
															'ai'
														),
													}));
											})
											.finally(() => {
												u(!1);
											}));
								},
								[s, e.toggle.restField]
							),
							f = (0, n.useCallback)(
								(s, t) => {
									const n = i[s],
										r = { ...i, [s]: t };
									(a(r),
										u(!0),
										x(null),
										o()({
											path: '/wp/v2/settings',
											method: 'POST',
											data: {
												[e.featureToggles.restField]: r,
											},
										})
											.then(() => {
												x({
													status: 'success',
													message: (0, l.__)(
														'Feature setting updated.',
														'ai'
													),
												});
											})
											.catch(() => {
												(a({
													...i,
													[s]: void 0 === n || n,
												}),
													x({
														status: 'error',
														message: (0, l.__)(
															'Saving failed. Please try again.',
															'ai'
														),
													}));
											})
											.finally(() => {
												u(!1);
											}));
								},
								[i, e.featureToggles.restField]
							);
						return h
							? (0, c.jsxs)('div', {
									className: 'ai-experiments-settings-app',
									children: [
										m
											? (0, c.jsx)(r.Notice, {
													status: m.status,
													isDismissible: !0,
													onRemove: () => x(null),
													children: m.message,
												})
											: null,
										(0, c.jsx)(p, {
											section: h,
											enabled: s,
											isSaving: d,
											onChange: v,
										}),
										_.length > 0
											? (0, c.jsxs)(n.Fragment, {
													children: [
														(0, c.jsx)('div', {
															className:
																'ai-experiments-settings-app__divider',
														}),
														(0, c.jsx)('div', {
															className:
																'ai-experiments-settings-app__sections',
															children: _.map(
																(e) =>
																	(0, c.jsx)(
																		g,
																		{
																			section:
																				e,
																			masterEnabled:
																				s,
																			isSaving:
																				d,
																			onToggle:
																				f,
																		},
																		e.id
																	)
															),
														}),
													],
												})
											: (0, c.jsx)('p', {
													className:
														'ai-experiments-settings-app__empty',
													children: (0, l.__)(
														'Additional experimental features will surface their configuration here.',
														'ai'
													),
												}),
									],
								})
							: (0, c.jsx)('div', {
									className: 'ai-experiments-settings-app',
									children: (0, c.jsx)(r.Notice, {
										status: 'warning',
										isDismissible: !1,
										children: (0, l.__)(
											'No settings sections are currently registered.',
											'ai'
										),
									}),
								});
					};
				a()(() => {
					var e, s;
					const t = document.getElementById(
						'ai-experiments-settings-root'
					);
					if (!t) return;
					const i =
						null !== (e = window.wpAiExperimentsSettings) &&
						void 0 !== e
							? e
							: t.getAttribute('data-settings')
								? JSON.parse(
										null !==
											(s =
												t.getAttribute(
													'data-settings'
												)) && void 0 !== s
											? s
											: '{}'
									)
								: null;
					if (!i) return;
					t.removeAttribute('data-settings');
					const a = t.closest('.ai-experiments-settings');
					(a && a.classList.add('is-app-ready'),
						((e, s) => {
							'function' != typeof n.createRoot
								? (0, n.render)(
										(0, c.jsx)(u, { settings: s }),
										e
									)
								: (0, n.createRoot)(e).render(
										(0, c.jsx)(u, { settings: s })
									);
						})(t, i));
				});
			},
		},
		t = {};
	function i(e) {
		var a = t[e];
		if (void 0 !== a) return a.exports;
		var n = (t[e] = { exports: {} });
		return (s[e](n, n.exports, i), n.exports);
	}
	((i.m = s),
		(e = []),
		(i.O = (s, t, a, n) => {
			if (!t) {
				var r = 1 / 0;
				for (c = 0; c < e.length; c++) {
					for (var [t, a, n] = e[c], l = !0, d = 0; d < t.length; d++)
						(!1 & n || r >= n) &&
						Object.keys(i.O).every((e) => i.O[e](t[d]))
							? t.splice(d--, 1)
							: ((l = !1), n < r && (r = n));
					if (l) {
						e.splice(c--, 1);
						var o = a();
						void 0 !== o && (s = o);
					}
				}
				return s;
			}
			n = n || 0;
			for (var c = e.length; c > 0 && e[c - 1][2] > n; c--)
				e[c] = e[c - 1];
			e[c] = [t, a, n];
		}),
		(i.n = (e) => {
			var s = e && e.__esModule ? () => e.default : () => e;
			return (i.d(s, { a: s }), s);
		}),
		(i.d = (e, s) => {
			for (var t in s)
				i.o(s, t) &&
					!i.o(e, t) &&
					Object.defineProperty(e, t, { enumerable: !0, get: s[t] });
		}),
		(i.o = (e, s) => Object.prototype.hasOwnProperty.call(e, s)),
		(() => {
			var e = { 57: 0, 350: 0 };
			i.O.j = (s) => 0 === e[s];
			var s = (s, t) => {
					var a,
						n,
						[r, l, d] = t,
						o = 0;
					if (r.some((s) => 0 !== e[s])) {
						for (a in l) i.o(l, a) && (i.m[a] = l[a]);
						if (d) var c = d(i);
					}
					for (s && s(t); o < r.length; o++)
						((n = r[o]),
							i.o(e, n) && e[n] && e[n][0](),
							(e[n] = 0));
					return i.O(c);
				},
				t = (globalThis.webpackChunkai =
					globalThis.webpackChunkai || []);
			(t.forEach(s.bind(null, 0)),
				(t.push = s.bind(null, t.push.bind(t))));
		})());
	var a = i.O(void 0, [350], () => i(493));
	a = i.O(a);
})();
