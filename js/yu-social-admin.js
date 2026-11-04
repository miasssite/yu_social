/**
 * @file
 * Drag & drop reordering for social link rows.
 */
(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.socialLinksRows = {
    attach(context) {
      once('social-links-drag', '.social-links-rows', context).forEach((container) => {
        let dragged = null;

        const updateWeights = () => {
          container
            .querySelectorAll('.social-links-row')
            .forEach((row, index) => {
              const input = row.querySelector('input[name$="[weight]"]');
              if (input) {
                input.value = index;
              }
            });
        };

        container.querySelectorAll('.social-links-row').forEach((row) => {
          // Only the summary line starts a drag; clicking it still toggles
          // the row open/closed.
          const summary = row.querySelector('summary');
          if (summary) {
            summary.addEventListener('mousedown', () => {
              row.draggable = true;
            });
          }
          row.addEventListener('mouseup', () => {
            row.draggable = false;
          });

          row.addEventListener('dragstart', (e) => {
            dragged = row;
            row.classList.add('is-dragging');
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', '');
          });

          row.addEventListener('dragend', () => {
            row.classList.remove('is-dragging');
            row.draggable = false;
            dragged = null;
            updateWeights();
          });

          row.addEventListener('dragover', (e) => {
            if (!dragged || dragged === row) {
              return;
            }
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            const rect = row.getBoundingClientRect();
            const before = e.clientY < rect.top + rect.height / 2;
            container.insertBefore(dragged, before ? row : row.nextSibling);
          });
        });

        container.addEventListener('drop', (e) => {
          e.preventDefault();
          updateWeights();
        });
      });
    },
  };
})(Drupal, once);
