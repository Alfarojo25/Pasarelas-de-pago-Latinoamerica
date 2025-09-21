(function($){
	function parseLines(txt){
		return txt.trim()? txt.trim().split(/\r?\n/):[];
	}
	function rebuildTextarea(id, rows){
		$(id).val(rows.join('\n'));
	}
	function initTableRates(){
		const $ta = $('#plugin_monedas_rates');
		const rows = parseLines($ta.val());
		const $tbody = $('#pm-table-rates tbody');
		rows.forEach(l=>{ const p=l.split('|'); if(p.length===3){ addRateRow(p[0],p[1],p[2]); }});
		function addRateRow(cod='',sym='',rate=''){
			const $tr = $('<tr/>');
			$tr.append('<td><input type="text" class="pm-cod" value="'+cod+'" maxlength="10" /></td>');
			$tr.append('<td><input type="text" class="pm-sym" value="'+sym.replace(/"/g,'&quot;')+'" /></td>');
			$tr.append('<td><input type="text" class="pm-rate" value="'+rate+'" /></td>');
			$tr.append('<td><span class="pm-remove" title="Eliminar">✕</span></td>');
			$tbody.append($tr);
		}
		$('[data-pm-add-row="rates"]').on('click',()=>{ addRateRow(); });
		$tbody.on('click','.pm-remove',function(){ $(this).closest('tr').remove(); sync(); });
		$tbody.on('input','input',sync);
		function sync(){
			const out=[]; $tbody.find('tr').each(function(){
				const c=$(this).find('.pm-cod').val().toUpperCase().replace(/[^A-Z]/g,'');
				const s=$(this).find('.pm-sym').val();
				const r=$(this).find('.pm-rate').val();
				if(c && r) out.push(c+'|'+s+'|'+r);
			});
			rebuildTextarea('#plugin_monedas_rates',out);
		}
	}
	function initGenericTable(config){
		const {taId,tableId,cols,addAttr,buildLine} = config;
		const $ta=$(taId), $tbody=$(tableId+' tbody');
		parseLines($ta.val()).forEach(l=>{ const p=l.split('|'); addRow(p); });
		function addRow(p){
			const $tr=$('<tr/>');
			for(let i=0;i<cols.length;i++){
				const def = p[i]||''; const meta=cols[i];
				if(meta.type==='select'){
					let opts=''; meta.options.forEach(o=>{ opts+='<option value="'+o+'"'+(o===def?' selected':'')+'>'+o+'</option>'; });
					$tr.append('<td><select class="pm-col pm-col-'+i+'">'+opts+'</select></td>');
				}else{
					$tr.append('<td><input type="text" class="pm-col pm-col-'+i+'" value="'+def+'" /></td>');
				}
			}
			$tr.append('<td><span class="pm-remove" title="Eliminar">✕</span></td>');
			$tbody.append($tr);
		}
		$(addAttr).on('click',()=>{ addRow([]); });
		$tbody.on('click','.pm-remove',function(){ $(this).closest('tr').remove(); sync(); });
		$tbody.on('input change','input,select',sync);
		function sync(){
			const out=[];$tbody.find('tr').each(function(){
				const parts=[];$(this).find('.pm-col').each(function(){ let v=$(this).val().trim(); parts.push(v); });
				if(parts.filter(Boolean).length>=cols.length-1){ out.push(buildLine(parts)); }
			});
			$(taId).val(out.join('\n'));
		}
	}
	$(function(){
		if($('#pm-table-rates').length){ initTableRates(); }
		initGenericTable({
			taId:'#plugin_monedas_cash_round_map',
			tableId:'#pm-table-cash',
			cols:[{},{ }],
			addAttr:'[data-pm-add-row="cash"]',
			buildLine:(p)=> (p[0].toUpperCase().replace(/[^A-Z]/g,'')+'|'+p[1])
		});
		initGenericTable({
			taId:'#plugin_monedas_magnitude_round_map',
			tableId:'#pm-table-mag',
			cols:[{},{},{ type:'select', options:['nearest','up','down'] }],
			addAttr:'[data-pm-add-row="mag"]',
			buildLine:(p)=> (p[0].toUpperCase().replace(/[^A-Z]/g,'')+'|'+p[1]+'|'+(p[2]||'nearest'))
		});
		initGenericTable({
			taId:'#plugin_monedas_geo_map',
			tableId:'#pm-table-geo',
			cols:[{},{}],
			addAttr:'[data-pm-add-row="geo"]',
			buildLine:(p)=> (p[0].toUpperCase().replace(/[^A-Z]/g,'').substring(0,2)+'|'+p[1].toUpperCase().replace(/[^A-Z]/g,''))
		});
		initGenericTable({
			taId:'#plugin_monedas_decimals',
			tableId:'#pm-table-dec',
			cols:[{},{}],
			addAttr:'[data-pm-add-row="dec"]',
			buildLine:(p)=> (p[0].toUpperCase().replace(/[^A-Z]/g,'')+'|'+p[1])
		});
		initGenericTable({
			taId:'#plugin_monedas_format_map',
			tableId:'#pm-table-fmt',
			cols:[{}, {}, {}],
			addAttr:'[data-pm-add-row="fmt"]',
			buildLine:(p)=> (p[0].toUpperCase().replace(/[^A-Z]/g,'')+'|'+p[1]+'|'+p[2])
		});
		initGenericTable({
			taId:'#plugin_monedas_currency_country_map',
			tableId:'#pm-table-cur-country',
			cols:[{},{}],
			addAttr:'[data-pm-add-row="cur-country"]',
			buildLine:(p)=> (p[0].toUpperCase().replace(/[^A-Z]/g,'')+'|'+p[1].toUpperCase().replace(/[^A-Z]/g,'').substring(0,2))
		});
	});
})(jQuery);
