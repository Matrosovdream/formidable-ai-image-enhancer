jQuery(function ($) {

  // -----------------------------
  // Model description helper
  // -----------------------------
  function updateDesc($select) {
    var api = $select.data("api");
    var model = $select.val() || "";
    var $desc = $select.closest("div").find(".fo-model-desc");

    if (!api || !$desc.length) return;

    var map = (window.FRM_IMAGE_ENHANCER && FRM_IMAGE_ENHANCER.model_descriptions)
      ? FRM_IMAGE_ENHANCER.model_descriptions
      : {};

    var apiMap = map[api] || {};
    var text = apiMap[model] || "";

    if (!model) {
      $desc.text("");
      return;
    }

    if (!text) text = "Selected model: " + model;
    $desc.text(text);
  }

  $(".fo-model-select").each(function () {
    updateDesc($(this));
  });

  $(document).on("change", ".fo-model-select", function () {
    updateDesc($(this));
  });


  // -----------------------------
  // Enhancer: Default prompts add/remove + sync Selected
  // -----------------------------
  function syncRowSelected($row) {
    var $chk = $row.find(".fo-prompt-check");
    var $hidden = $row.find('input[type="hidden"][name="enhancer[default_prompts_selected][]"]');
    if (!$chk.length || !$hidden.length) return;
    $hidden.val($chk[0].checked ? "1" : "0");
  }

  // init existing rows
  $("#foDefaultPrompts .fo-prompt-row").each(function () {
    syncRowSelected($(this));
  });

  $(document).on("change", ".fo-prompt-check", function () {
    syncRowSelected($(this).closest(".fo-prompt-row"));
  });

  $(document).on("click", "#foPromptAdd", function () {
    var $row = $(
      '<div class="fo-prompt-row">' +

        '<label class="fo-prompt-title-label">Title</label>' +
        '<input type="text" class="regular-text fo-prompt-title" name="enhancer[default_prompts_title][]" placeholder="Prompt title..." value="">' +

        '<label class="fo-prompt-text-label">Text</label>' +
        '<textarea class="large-text fo-prompt-text" rows="4" name="enhancer[default_prompts_text][]" placeholder="Enter prompt..."></textarea>' +

        '<input type="hidden" name="enhancer[default_prompts_selected][]" value="1">' +

        '<label class="fo-prompt-selected">' +
          '<input type="checkbox" class="fo-prompt-check" value="1" checked> Selected' +
        '</label>' +

        '<button type="button" class="fo-prompt-remove" aria-label="Remove prompt" title="Remove">×</button>' +
      '</div>'
    );

    $("#foDefaultPrompts").append($row);
  });

  $(document).on("click", ".fo-prompt-remove", function () {
    $(this).closest(".fo-prompt-row").remove();
  });

});
