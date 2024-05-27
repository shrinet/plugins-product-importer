function import_data(e) {
  jQuery("#msg-loading").show();
  jQuery.ajax({
    cache: false,
    type: "POST",
    url: wp_ajax_url,
    data: {
      action: "import_data",
      import_products: jQuery("#importProducts").is(":checked")
        ? jQuery("#importProducts").val()
        : "",
      import_categories: jQuery("#importCategories").is(":checked")
        ? jQuery("#importCategories").val()
        : "",
      import_category_metadata: jQuery("#importCategoriesMeta").is(":checked")
        ? jQuery("#importCategoriesMeta").val()
        : "",
      import_permalinks: jQuery("#importPermalinks").is(":checked")
        ? jQuery("#importPermalinks").val()
        : "",
      export_categories: jQuery("#exportCategories").is(":checked")
        ? jQuery("#exportCategories").val()
        : "",
      extra_folder: jQuery("#extraFolder").val()
    },
    success: function(data) {
      jQuery("#msg-loading").hide();
      alert(data);
    },
    fail: function(data) {
      jQuery("#msg-loading").hide();
      alert("Download Failed, please try again.");
    },
    error: function(jqXhr, textStatus, errorThrown) {
      import_data(e);
    }
  });
}
