    </div>
  </div>
</div>
<?= $PAGE_FOOT ?? '' ?>
<script>
/* Give every data-table cell the label of its column, so the mobile
   stylesheet can stack rows into readable cards instead of a squeezed grid. */
(function(){
  document.querySelectorAll('table.data').forEach(function(t){
    var head = t.querySelector('tr');
    if (!head) return;
    var labels = [].map.call(head.children, function(c){ return c.textContent.trim(); });
    [].forEach.call(t.rows, function(row, i){
      if (i === 0) return;
      if (row.children.length !== labels.length) { row.classList.add('rowspan'); return; }
      [].forEach.call(row.children, function(cell, j){
        if (cell.hasAttribute('colspan')) { row.classList.add('rowspan'); return; }
        if (labels[j]) cell.setAttribute('data-label', labels[j]);
      });
    });
  });
})();

/* Turn any <select class="searchable"> into a type-to-filter combobox.
   Keeps the original <select> as the actual form value, so submit behaviour
   (including onchange) is unchanged; the text box just drives it. */
(function(){
  document.querySelectorAll('select.searchable').forEach(function(sel){
    if (sel.dataset.enhanced) return;
    sel.dataset.enhanced = '1';

    var wrap = document.createElement('div');
    wrap.className = 'ss-wrap';
    sel.parentNode.insertBefore(wrap, sel);
    wrap.appendChild(sel);
    sel.classList.add('ss-hidden');

    var input = document.createElement('input');
    input.type = 'text';
    input.className = 'ss-input';
    input.autocomplete = 'off';
    input.placeholder = sel.getAttribute('data-placeholder') || 'Type to search…';
    var cur = sel.options[sel.selectedIndex];
    if (cur && cur.value) input.value = cur.textContent.trim();
    wrap.appendChild(input);

    var list = document.createElement('div');
    list.className = 'ss-list';
    wrap.appendChild(list);

    var opts = [].slice.call(sel.options).map(function(o){
      return { value: o.value, label: o.textContent.trim() };
    }).filter(function(o){ return o.value !== ''; });

    function render(filter){
      list.innerHTML = '';
      var f = (filter || '').toLowerCase();
      var matches = opts.filter(function(o){ return o.label.toLowerCase().indexOf(f) !== -1; });
      if (!matches.length){
        var none = document.createElement('div');
        none.className = 'ss-none';
        none.textContent = 'No match';
        list.appendChild(none);
        return;
      }
      matches.slice(0, 50).forEach(function(o){
        var item = document.createElement('div');
        item.className = 'ss-item';
        item.textContent = o.label;
        item.addEventListener('mousedown', function(e){
          e.preventDefault();
          sel.value = o.value;
          input.value = o.label;
          close();
          sel.dispatchEvent(new Event('change', { bubbles: true }));
        });
        list.appendChild(item);
      });
    }
    function open(){ render(''); wrap.classList.add('ss-open'); }
    function close(){ wrap.classList.remove('ss-open'); }

    input.addEventListener('focus', open);
    input.addEventListener('input', function(){ wrap.classList.add('ss-open'); render(input.value); });
    input.addEventListener('blur', function(){
      setTimeout(function(){
        close();
        // if the typed text doesn't exactly match a chosen option, restore label
        var chosen = sel.options[sel.selectedIndex];
        input.value = (chosen && chosen.value) ? chosen.textContent.trim() : '';
      }, 150);
    });
  });
})();
</script>
</body>
</html>
