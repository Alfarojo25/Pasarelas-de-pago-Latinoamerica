(function($){
	function parseLines(txt){ return txt && txt.trim()? txt.trim().split(/\r?\n/):[]; }
	function unique(arr){ return [...new Set(arr)]; }
	const D = window.PluginMonedasData||{};

	function buildExistingIndex(){
		const index = {};
		// rates: CODE|SYM|RATE
		parseLines(D.existing.rates||'').forEach(l=>{ const p=l.split('|'); if(p.length===3){ index[p[0]]=index[p[0]]||{}; index[p[0]].rate=p[2]; index[p[0]].symbol=p[1]; }});
		// cash: CODE|INC
		parseLines(D.existing.cash||'').forEach(l=>{ const p=l.split('|'); if(p.length===2){ index[p[0]]=index[p[0]]||{}; index[p[0]].cash=p[1]; }});
		// mag: CODE|MAG|MODE
		parseLines(D.existing.mag||'').forEach(l=>{ const p=l.split('|'); if(p.length===3){ index[p[0]]=index[p[0]]||{}; index[p[0]].mag=p[1]; index[p[0]].magMode=p[2]; }});
		// geo (country->currency)
		const geoMap = {};
		parseLines(D.existing.geo||'').forEach(l=>{ const p=l.split('|'); if(p.length===2){ geoMap[p[0]]=p[1]; }});
		// currency->country (para Forzar país fiscal)
		const curCountry = {};
		parseLines(D.existing.curCountry||'').forEach(l=>{ const p=l.split('|'); if(p.length===2){ curCountry[p[0]]=p[1]; }});
		// decimals CODE|N
		parseLines(D.existing.decimals||'').forEach(l=>{ const p=l.split('|'); if(p.length===2){ index[p[0]]=index[p[0]]||{}; index[p[0]].dec=p[1]; }});
		// gateway map gateway|CUR1,CUR2
		const gatewayMap = {};
		parseLines(D.existing.gatewayMap||'').forEach(l=>{ const p=l.split('|'); if(p.length===2){ gatewayMap[p[0]]=p[1].split(','); }});
		return { index, geoMap, curCountry, gatewayMap };
	}

	function guessDecimals(cur){
		if(D.zeroDecimals && D.zeroDecimals.includes(cur)) return 0;
		if(D.threeDecimals && D.threeDecimals.includes(cur)) return 3;
		return 2;
	}

	function createGatewayToggles(selected){
		selected = selected||[];
		let html = '<div class="pm-gw-wrapper">';
		html += '<div class="pm-gw-actions">'
			+'<button type="button" class="button button-small pm-gw-all-on" title="'+(D.i18n && D.i18n['Activar todas']?D.i18n['Activar todas']:'Activar todas')+'">✔</button>'
			+'<button type="button" class="button button-small pm-gw-all-off" title="'+(D.i18n && D.i18n['Desactivar todas']?D.i18n['Desactivar todas']:'Desactivar todas')+'">✖</button>'
			+'<span class="pm-gw-none badge" style="display:none;">'+(D.i18n && D.i18n['Ninguna activa']?D.i18n['Ninguna activa']:'Ninguna activa')+'</span>'
			+'<span class="pm-gw-count" data-count="0">(0/'+((D.gateways||[]).length)+')</span>'
			+'</div>';
		html += '<div class="pm-gw-list">';
		(D.gateways||[]).forEach(g=>{
			const checked = selected.includes(g.id)?' checked':'';
			const tooltip = g.title + (g.description? (' - '+g.description):'');
			html += '<label class="pm-gw-item" title="'+tooltip.replace(/"/g,'&quot;')+'">'
				+'<span class="pm-switch"><input type="checkbox" class="pm-gw" data-gid="'+g.id+'"'+checked+' /><span></span></span>'
				+'<span class="pm-gw-title" data-full="'+g.title.replace(/"/g,'&quot;')+'">'+g.title+'</span>'
				+'</label>';
		});
		html+='</div></div>';
		return html;
	}

		function applyCash(price, inc){ inc=parseFloat(inc); if(!(inc>0)) return price; return Math.round(price/inc)*inc; }
		function applyMag(price, spec){ if(!spec) return price; const parts=spec.split(':'); const mag=parseFloat(parts[0]); const mode=(parts[1]||'nearest'); if(!(mag>0)) return price; const div=price/mag; if(mode==='nearest') return Math.round(div)*mag; if(mode==='up') return Math.ceil(div)*mag; if(mode==='down') return Math.floor(div)*mag; return price; }
			function formatPrice(val, decimals){
				decimals = isNaN(decimals)?2:decimals; const d = D.numberFormat || { decimal: '.', thousand: ',', decimals: decimals };
				const fixed = Number(val).toFixed(decimals);
				const parts = fixed.split('.');
				let int = parts[0];
				const frac = parts[1] ? d.decimal + parts[1] : '';
				int = int.replace(/\B(?=(\d{3})+(?!\d))/g, d.thousand);
				return int + frac;
			}
			function exampleFor(cash, mag, decimals){ let p=12345.67; if(cash) p=applyCash(p,cash); if(mag) p=applyMag(p,mag); return formatPrice(p, decimals); }

	function syncHidden(masterRows){
			// MasterRows: {country,currency,symbol,rate,decimals,cashInc,magSpec,tax,gatewayIds}
			const rates=[]; const cash=[]; const mag=[]; const geo=[]; const curCountry=[]; const dec=[]; const gateways=[]; const exempt=[];
			masterRows.forEach(r=>{
				if(r.currency && r.rate){ rates.push(r.currency+'|'+r.symbol+'|'+r.rate); }
				if(r.country && r.currency){ geo.push(r.country+'|'+r.currency); curCountry.push(r.currency+'|'+r.country); }
				if(r.decimals!==undefined){ dec.push(r.currency+'|'+r.decimals); }
				if(r.cashInc){ cash.push(r.currency+'|'+r.cashInc); }
				if(r.magSpec){ const parts=r.magSpec.split(':'); mag.push(r.currency+'|'+parts[0]+'|'+(parts[1]||'nearest')); }
				if(r.tax===false){ exempt.push(r.currency); }
			});
		// gateways map gateway|CUR1,CUR2
		const gmap={}; masterRows.forEach(r=>{ (r.gatewayIds||[]).forEach(gid=>{ gmap[gid]=gmap[gid]||[]; gmap[gid].push(r.currency); }); });
		Object.keys(gmap).forEach(k=>{ gateways.push(k+'|'+unique(gmap[k]).join(',')); });
		$('#plugin_monedas_rates').val(rates.join('\n'));
		$('#plugin_monedas_cash_round_map').val(cash.join('\n'));
		$('#plugin_monedas_magnitude_round_map').val(mag.join('\n'));
		$('#plugin_monedas_geo_map').val(unique(geo).join('\n'));
		$('#plugin_monedas_currency_country_map').val(unique(curCountry).join('\n'));
		$('#plugin_monedas_decimals').val(unique(dec).join('\n'));
		$('#plugin_monedas_gateway_currency_map').val(gateways.join('\n'));
		$('#plugin_monedas_exempt_currencies').val(unique(exempt).join('\n'));
	}

	function initMaster(){
		const $table=$('#pm-table-master tbody'); if(!$table.length) return;
		const existing = buildExistingIndex();
		const reverseCountryCurrency = {}; Object.keys(D.countryCurrency||{}).forEach(cc=>{ const cur=D.countryCurrency[cc][0]; reverseCountryCurrency[cur]=cc; });
		// Reconstruir filas desde rates como base.
			const baseCodes = Object.keys(existing.index);
			baseCodes.forEach(code=>{
				const rowData = existing.index[code];
				const country = existing.curCountry[code] || reverseCountryCurrency[code] || '';
				const magSpec = rowData.mag? (rowData.mag+':' + (rowData.magMode||'nearest')):'';
				// Determinar gateways asociados a esta moneda desde el mapa existente.gatewayMap (gateway -> [monedas])
				const gatewayIds = [];
				Object.keys(existing.gatewayMap||{}).forEach(gid=>{ if((existing.gatewayMap[gid]||[]).includes(code)) gatewayIds.push(gid); });
				addRow({ country, currency: code, symbol: rowData.symbol || (D.currencySymbols? (D.currencySymbols[code]||''):''), rate: rowData.rate||'', decimals: rowData.dec || guessDecimals(code), cashInc: rowData.cash||'', magSpec, tax: true, gatewayIds });
			});
			if(!baseCodes.length){ addRow(); }
		$('#pm-master-add').on('click',()=> addRow());

		function countrySelectHtml(selected){
			let h='<select class="pm-country"><option value="">'+pm_i18n('Seleccionar')+'</option>';
			Object.keys(D.countryCurrency||{}).sort().forEach(c=>{ const cur=D.countryCurrency[c][0]; const name=D.countryCurrency[c][1]; const sel=c===selected?' selected':''; h+='<option value="'+c+'" data-currency="'+cur+'">'+name+' ('+c+')</option>'; });
			h+='</select>'; return h; }
			function pm_i18n(s){ return (D.i18n && D.i18n[s])? D.i18n[s]: s; }
			function roundMethodHtml(){ return '<div class="pm-round-group">'
				+'<label>'+pm_i18n('Cash')+': <input type="text" class="pm-cash" placeholder="50" size="4" /></label><br>'
				+'<label>'+pm_i18n('Magnitud')+': <input type="text" class="pm-mag" placeholder="1000:nearest" size="10" /></label>'
				+'</div>'; }

			function addRow(data){ data=data||{}; const $tr=$('<tr/>');
			$tr.append('<td>'+countrySelectHtml(data.country||'')+'</td>');
			$tr.append('<td><input type="text" class="pm-currency" value="'+(data.currency||'')+'" readonly /></td>');
			$tr.append('<td><input type="text" class="pm-symbol" value="'+(data.symbol||'')+'" readonly /></td>');
			$tr.append('<td><input type="text" class="pm-rate" value="'+(data.rate||'')+'" /></td>');
			$tr.append('<td><input type="number" min="0" max="6" class="pm-decimals" value="'+(data.decimals||2)+'" /></td>');
				$tr.append('<td>'+roundMethodHtml()+'</td>');
				$tr.append('<td><span class="pm-round-info"></span></td>');
			$tr.append('<td><label class="pm-switch"><input type="checkbox" class="pm-tax" '+((data.tax!==false)?'checked':'')+' /><span></span></label></td>');
			$tr.append('<td>'+createGatewayToggles(data.gatewayIds||[])+'</td>');
			$tr.append('<td class="pm-example"></td>');
			$tr.append('<td><span class="pm-remove" title="Eliminar">✕</span></td>');
			$table.append($tr); updateRow($tr); }

			function updateRow($tr){
			const country=$tr.find('.pm-country').val();
			if(country){ const cc=D.countryCurrency[country]; if(cc){ const cur=cc[0]; const name=cc[1]; $tr.find('.pm-currency').val(cur); if(!$tr.find('.pm-symbol').val()){ const sym=D.currencySymbols? (D.currencySymbols[cur]||''):''; $tr.find('.pm-symbol').val(sym); } if(!$tr.find('.pm-rate').val()) $tr.find('.pm-rate').val('1.00'); if(!$tr.find('.pm-decimals').data('user')){ $tr.find('.pm-decimals').val(guessDecimals(cur)); } }
			}
				const cashVal=$tr.find('.pm-cash').val(); const magVal=$tr.find('.pm-mag').val();
				const decimals = parseInt($tr.find('.pm-decimals').val(),10); $tr.find('.pm-example').text(exampleFor(cashVal,magVal,decimals));
				validateRow($tr);
			syncAll();
		}
			$table.on('change','.pm-country',function(){ updateRow($(this).closest('tr')); });
			$table.on('input change','.pm-rate,.pm-decimals,.pm-cash,.pm-mag,.pm-tax,.pm-gw',function(){ if($(this).hasClass('pm-decimals')) $(this).data('user',true); updateRow($(this).closest('tr')); updateGatewayIndicator($(this).closest('tr')); });

			// Botones activar/desactivar todas
			$table.on('click','.pm-gw-all-on',function(){ const $tr=$(this).closest('tr'); $tr.find('.pm-gw').prop('checked',true); updateGatewayIndicator($tr); updateRow($tr); });
			$table.on('click','.pm-gw-all-off',function(){ const $tr=$(this).closest('tr'); $tr.find('.pm-gw').prop('checked',false); updateGatewayIndicator($tr); updateRow($tr); });

			function updateGatewayIndicator($tr){
				const total = $tr.find('.pm-gw').length;
				const active = $tr.find('.pm-gw:checked').length;
				$tr.find('.pm-gw-none').toggle(active===0);
				$tr.find('.pm-gw-count').text('('+active+'/'+total+')');
			}
		$table.on('click','.pm-remove',function(){ $(this).closest('tr').remove(); syncAll(); });

			function collect(){ const rows=[]; $table.find('tr').each(function(){ const $tr=$(this); const currency=$tr.find('.pm-currency').val(); if(!currency) return; const gatewayIds=[]; $tr.find('.pm-gw:checked').each(function(){ gatewayIds.push($(this).data('gid')); }); rows.push({ country:$tr.find('.pm-country').val(), currency:currency, symbol:$tr.find('.pm-symbol').val(), rate:$tr.find('.pm-rate').val(), decimals:parseInt($tr.find('.pm-decimals').val(),10), cashInc:$tr.find('.pm-cash').val(), magSpec:$tr.find('.pm-mag').val(), tax:$tr.find('.pm-tax').is(':checked'), gatewayIds }); }); return rows; }
			// Inicializar indicadores tras construir la tabla
			setTimeout(()=>{ $table.find('tr').each(function(){ updateGatewayIndicator($(this)); }); },50);
		function syncAll(){ syncHidden(collect()); }
			function validateRow($tr){
				$tr.removeClass('pm-error');
				const rate=parseFloat($tr.find('.pm-rate').val());
				const currency=$tr.find('.pm-currency').val();
				if(!currency || !(rate>0)){ $tr.addClass('pm-error'); }
			}
			// Prevenir países repetidos
			$table.on('change','.pm-country',function(){ const sel=$(this).val(); if(!sel) return; let count=0; $table.find('.pm-country').each(function(){ if($(this).val()===sel) count++; }); if(count>1){ alert(pm_i18n('Ese país ya está seleccionado.')); $(this).val(''); updateRow($(this).closest('tr')); } });
		syncAll();
	}

	$(function(){ initMaster(); });
})(jQuery);
