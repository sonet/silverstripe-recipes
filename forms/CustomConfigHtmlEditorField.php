<?php

/**
Copyright (c) 2007-2011, SilverStripe Limited - www.silverstripe.com
All rights reserved.

Redistribution and use in source and binary forms, with or without modification, are permitted provided that the following conditions are met:

    * Redistributions of source code must retain the above copyright notice, this list of conditions and the following disclaimer.
    * Redistributions in binary form must reproduce the above copyright notice, this list of conditions and the following disclaimer in the
      documentation and/or other materials provided with the distribution.
    * Neither the name of SilverStripe nor the names of its contributors may be used to endorse or promote products derived from this software
      without specific prior written permission.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT OWNER OR CONTRIBUTORS BE
LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE
GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT,
STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY
OF SUCH DAMAGE.
 */

/**
 * Sometimes you want two or more TinyMCE instances in the same page, and for each one to have it's own
 * tinymce configuration. This class lets you do just that.
 *
 * To use, pop something like this into your _config.php (this is a copy of what's in cms/_config, modified for
 * our purposes)

HtmlEditorConfig::get('linksonly')->setOptions(array(
	'friendly_name' => 'Links Only',
	'mode' => 'none',
	'language' => i18n::get_tinymce_lang(),

	'body_class' => 'typography',
	'document_base_url' => Director::absoluteBaseURL(),

	'urlconverter_callback' => "nullConverter",
	'setupcontent_callback' => "sapphiremce_setupcontent",
	'cleanup_callback' => "sapphiremce_cleanup",

	'use_native_selects' => true, // fancy selects are bug as of SS 2.3.0
	'valid_elements' => "@[id|class|style|title],#a[id|rel|rev|dir|tabindex|accesskey|type|name|href|target|title|class]",
	'extended_valid_elements' => "",

	'button_tile_map' => true
));

HtmlEditorConfig::get('linksonly')->enablePlugins(array('ssbuttons' => '../../../cms/javascript/tinymce_ssbuttons/editor_plugin_src.js'));

HtmlEditorConfig::get('linksonly')->setButtonsForLine(1, array('sslink'));
HtmlEditorConfig::get('linksonly')->setButtonsForLine(2, array());
HtmlEditorConfig::get('linksonly')->setButtonsForLine(3, array());

 *
 * Then use this formfield in a form somewhere, passing the config name as it's first argument
 *

function getCMSFields($fields = null) {
	$tab->push(new CustomConfigHtmlEditorField('linksonly', 'RelatedLinks', 'Related Links', 3, 20));
}

 */
class CustomConfigHtmlEditorField extends HtmlEditorField {

	public static function include_js($configName) {
		Requirements::javascript(MCE_ROOT . 'tiny_mce_src.js');

		$config = HtmlEditorConfig::get($configName);
		$config->setOption('mode', 'none');
		$config->setOption('editor_selector', "htmleditor$configName");

		Requirements::customScript("
Behaviour.register({
    'textarea.htmleditor$configName' : {
        initialize : function() {
            if(typeof tinyMCE != 'undefined'){
	            var oldsettings = tinyMCE.settings;
	            ".$config->generateJS()."
					tinyMCE.execCommand('mceAddControl', true, this.id);
					tinyMCE.settings = oldsettings;
					
	            this.isChanged = function() {
	                return tinyMCE.getInstanceById(this.id).isDirty();
	            }
	            this.resetChanged = function() {
	                inst = tinyMCE.getInstanceById(this.id);
	                if (inst) inst.startContent = tinymce.trim(inst.getContent({format : 'raw', no_events : 1}));
	            }
			}
        }
    }
})
		", "htmlEditorConfig-$configName");
	}

	public function __construct($config, $name, $title = null, $rows = 30, $cols = 20, $value = '', $form = null) {
		// Skip the HtmlEditorField's constructor
		TextareaField::__construct($name, $title, $rows, $cols, $value, $form);

		$this->addExtraClass('typography');
		$this->addExtraClass("htmleditor$config");

		self::include_js($config);
	}
}