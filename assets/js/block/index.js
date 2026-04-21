/* global wp */
(function () {
  'use strict';

  var registerBlockType = wp.blocks.registerBlockType;
  var InspectorControls = wp.blockEditor.InspectorControls;
  var MediaUpload = wp.blockEditor.MediaUpload;
  var MediaUploadCheck = wp.blockEditor.MediaUploadCheck;
  var ServerSideRender = wp.serverSideRender;
  var el = wp.element.createElement;
  var __ = wp.i18n.__;
  var PanelBody = wp.components.PanelBody;
  var SelectControl = wp.components.SelectControl;
  var TextControl = wp.components.TextControl;
  var ToggleControl = wp.components.ToggleControl;
  var Button = wp.components.Button;
  var Placeholder = wp.components.Placeholder;

  var policyOptions = [
    { label: __('Padrão (configurações)', 'signdocs-brasil'), value: '' },
    { label: __('Aceite Simples (Click)', 'signdocs-brasil'), value: 'CLICK_ONLY' },
    { label: __('Aceite + OTP', 'signdocs-brasil'), value: 'CLICK_PLUS_OTP' },
    { label: __('Verificação Facial', 'signdocs-brasil'), value: 'BIOMETRIC' },
    { label: __('Biometria + OTP', 'signdocs-brasil'), value: 'BIOMETRIC_PLUS_OTP' },
    { label: __('Certificado Digital A1', 'signdocs-brasil'), value: 'DIGITAL_CERTIFICATE' },
  ];

  var localeOptions = [
    { label: __('Padrão (configurações)', 'signdocs-brasil'), value: '' },
    { label: 'Português (Brasil)', value: 'pt-BR' },
    { label: 'English', value: 'en' },
    { label: 'Español', value: 'es' },
  ];

  var modeOptions = [
    { label: __('Padrão (configurações)', 'signdocs-brasil'), value: '' },
    { label: __('Redirecionamento', 'signdocs-brasil'), value: 'redirect' },
    { label: __('Popup', 'signdocs-brasil'), value: 'popup' },
    { label: __('Overlay', 'signdocs-brasil'), value: 'overlay' },
  ];

  registerBlockType('signdocs-brasil/signing-button', {
    edit: function (props) {
      var attributes = props.attributes;
      var setAttributes = props.setAttributes;

      var inspectorControls = el(
        InspectorControls,
        null,
        el(
          PanelBody,
          { title: __('Documento', 'signdocs-brasil'), initialOpen: true },
          el(
            MediaUploadCheck,
            null,
            el(MediaUpload, {
              onSelect: function (media) {
                setAttributes({ document_id: media.id });
              },
              allowedTypes: ['application/pdf'],
              value: attributes.document_id,
              render: function (obj) {
                return el(
                  'div',
                  null,
                  attributes.document_id
                    ? el('p', null, __('Documento ID:', 'signdocs-brasil') + ' ' + attributes.document_id)
                    : null,
                  el(
                    Button,
                    { onClick: obj.open, variant: 'secondary' },
                    attributes.document_id
                      ? __('Trocar Documento', 'signdocs-brasil')
                      : __('Selecionar PDF', 'signdocs-brasil')
                  )
                );
              },
            })
          )
        ),
        el(
          PanelBody,
          { title: __('Configuração', 'signdocs-brasil'), initialOpen: false },
          el(SelectControl, {
            label: __('Perfil de Assinatura', 'signdocs-brasil'),
            value: attributes.policy,
            options: policyOptions,
            onChange: function (val) { setAttributes({ policy: val }); },
          }),
          el(SelectControl, {
            label: __('Idioma', 'signdocs-brasil'),
            value: attributes.locale,
            options: localeOptions,
            onChange: function (val) { setAttributes({ locale: val }); },
          }),
          el(SelectControl, {
            label: __('Modo', 'signdocs-brasil'),
            value: attributes.mode,
            options: modeOptions,
            onChange: function (val) { setAttributes({ mode: val }); },
          }),
          el(TextControl, {
            label: __('Texto do Botão', 'signdocs-brasil'),
            value: attributes.button_text,
            onChange: function (val) { setAttributes({ button_text: val }); },
          }),
          el(TextControl, {
            label: __('URL de Retorno', 'signdocs-brasil'),
            value: attributes.return_url,
            onChange: function (val) { setAttributes({ return_url: val }); },
            help: __('Deixe vazio para usar a URL da página atual.', 'signdocs-brasil'),
          }),
          el(ToggleControl, {
            label: __('Exibir formulário (nome/email)', 'signdocs-brasil'),
            checked: attributes.show_form === 'true',
            onChange: function (val) { setAttributes({ show_form: val ? 'true' : 'false' }); },
          })
        )
      );

      var content;
      if (!attributes.document_id) {
        content = el(
          Placeholder,
          {
            icon: 'media-document',
            label: __('SignDocs Assinatura', 'signdocs-brasil'),
            instructions: __('Selecione um documento PDF na barra lateral para configurar o botão de assinatura.', 'signdocs-brasil'),
          }
        );
      } else {
        content = el(ServerSideRender, {
          block: 'signdocs-brasil/signing-button',
          attributes: attributes,
        });
      }

      return el('div', { className: props.className }, inspectorControls, content);
    },

    save: function () {
      // Dynamic block — rendered server-side
      return null;
    },
  });
})();
