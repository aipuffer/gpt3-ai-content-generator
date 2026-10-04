/** Ready-made AI Form drafts used by the overview template cards. */
(function () {
  "use strict";

  const createDraft = (key, title, prompt, fields) => {
    const slug = key.replace(/_/g, "-");
    return {
      template_key: key,
      title,
      prompt_template: prompt,
      conversation_ui_preset: "compact",
      structure: fields.map(([type, config], index) => ({
        internalId: `template-${slug}-row-${index + 1}`,
        type: "layout-row",
        columns: [{
          internalId: `template-${slug}-column-${index + 1}`,
          width: "100%",
          elements: [{
            internalId: `template-${slug}-field-${index + 1}`,
            type,
            required: false,
            helpText: "",
            ...config,
          }],
        }],
      })),
    };
  };

  const nameField = (__) => ["text-input", {
    label: __("Name", "gpt3-ai-content-generator"),
    placeholder: __("Your name", "gpt3-ai-content-generator"),
    fieldId: "name",
    required: true,
  }];

  const emailField = (__, required = true) => ["text-input", {
    label: required
      ? __("Email", "gpt3-ai-content-generator")
      : __("Email (optional)", "gpt3-ai-content-generator"),
    placeholder: __("you@example.com", "gpt3-ai-content-generator"),
    fieldId: "email",
    required,
  }];

  const templates = {
    lead_capture: (__) => createDraft(
      "lead_capture",
      __("Lead capture", "gpt3-ai-content-generator"),
      __(
        "Qualify this lead for the team. Summarize their contact details, intent, and the clearest next step.\n\nName: {name}\nEmail: {email}\nInterest: {interest}\nDetails: {details}",
        "gpt3-ai-content-generator"
      ),
      [
        nameField(__),
        emailField(__),
        ["select", {
          label: __("What are you interested in?", "gpt3-ai-content-generator"),
          placeholder: __("Choose an option", "gpt3-ai-content-generator"),
          fieldId: "interest",
          required: true,
          options: [
            { value: "product", text: __("Product", "gpt3-ai-content-generator") },
            { value: "services", text: __("Services", "gpt3-ai-content-generator") },
            { value: "partnership", text: __("Partnership", "gpt3-ai-content-generator") },
          ],
        }],
        ["textarea", {
          label: __("Tell us more", "gpt3-ai-content-generator"),
          placeholder: __("What would you like help with?", "gpt3-ai-content-generator"),
          fieldId: "details",
        }],
      ]
    ),
    customer_feedback: (__) => createDraft(
      "customer_feedback",
      __("Customer feedback", "gpt3-ai-content-generator"),
      __(
        "Summarize this customer feedback, identify the sentiment and main theme, then recommend one practical follow-up.\n\nRating: {rating}\nExperience: {experience}\nContact: {email}",
        "gpt3-ai-content-generator"
      ),
      [
        ["select", {
          label: __("How would you rate your experience?", "gpt3-ai-content-generator"),
          placeholder: __("Choose a rating", "gpt3-ai-content-generator"),
          fieldId: "rating",
          required: true,
          options: [1, 2, 3, 4, 5].map((rating) => ({
            value: String(rating),
            text: `${rating} / 5`,
          })),
        }],
        ["textarea", {
          label: __("Tell us about your experience", "gpt3-ai-content-generator"),
          placeholder: __("What worked well, and what could improve?", "gpt3-ai-content-generator"),
          fieldId: "experience",
          required: true,
        }],
        emailField(__, false),
      ]
    ),
    book_appointment: (__) => createDraft(
      "book_appointment",
      __("Book appointment", "gpt3-ai-content-generator"),
      __(
        "Prepare a concise appointment request for the team. Include the visitor's contact details, requested date and time, and reason for the meeting.\n\nName: {name}\nEmail: {email}\nPreferred date: {preferred_date}\nPreferred time: {preferred_time}\nReason: {reason}",
        "gpt3-ai-content-generator"
      ),
      [
        nameField(__),
        emailField(__),
        ["text-input", {
          label: __("Preferred date", "gpt3-ai-content-generator"),
          placeholder: __("e.g. October 24", "gpt3-ai-content-generator"),
          fieldId: "preferred_date",
          required: true,
        }],
        ["text-input", {
          label: __("Preferred time", "gpt3-ai-content-generator"),
          placeholder: __("e.g. 2:30 PM", "gpt3-ai-content-generator"),
          fieldId: "preferred_time",
          required: true,
        }],
        ["textarea", {
          label: __("What would you like to discuss?", "gpt3-ai-content-generator"),
          placeholder: __("Add a short note", "gpt3-ai-content-generator"),
          fieldId: "reason",
        }],
      ]
    ),
    support_request: (__) => createDraft(
      "support_request",
      __("Support request", "gpt3-ai-content-generator"),
      __(
        "Triage this support request. Return a short issue summary, urgency, suggested owner, and the next response the support team should send.\n\nName: {name}\nEmail: {email}\nIssue type: {issue_type}\nUrgency: {urgency}\nMessage: {message}",
        "gpt3-ai-content-generator"
      ),
      [
        nameField(__),
        emailField(__),
        ["select", {
          label: __("Issue type", "gpt3-ai-content-generator"),
          placeholder: __("Choose an issue", "gpt3-ai-content-generator"),
          fieldId: "issue_type",
          required: true,
          options: [
            { value: "billing", text: __("Billing", "gpt3-ai-content-generator") },
            { value: "technical", text: __("Technical", "gpt3-ai-content-generator") },
            { value: "account", text: __("Account", "gpt3-ai-content-generator") },
            { value: "other", text: __("Other", "gpt3-ai-content-generator") },
          ],
        }],
        ["select", {
          label: __("Urgency", "gpt3-ai-content-generator"),
          placeholder: __("Choose urgency", "gpt3-ai-content-generator"),
          fieldId: "urgency",
          required: true,
          options: [
            { value: "low", text: __("Low", "gpt3-ai-content-generator") },
            { value: "normal", text: __("Normal", "gpt3-ai-content-generator") },
            { value: "urgent", text: __("Urgent", "gpt3-ai-content-generator") },
          ],
        }],
        ["textarea", {
          label: __("How can we help?", "gpt3-ai-content-generator"),
          placeholder: __("Describe the issue", "gpt3-ai-content-generator"),
          fieldId: "message",
          required: true,
        }],
      ]
    ),
    waitlist_signup: (__) => createDraft(
      "waitlist_signup",
      __("Waitlist signup", "gpt3-ai-content-generator"),
      __(
        "Summarize this waitlist signup for the product team. Highlight the person's role, organization, and strongest reason for joining.\n\nName: {name}\nEmail: {email}\nRole: {role}\nOrganization: {organization}\nInterest: {interest}",
        "gpt3-ai-content-generator"
      ),
      [
        nameField(__),
        emailField(__),
        ["text-input", {
          label: __("Role", "gpt3-ai-content-generator"),
          placeholder: __("e.g. Product manager", "gpt3-ai-content-generator"),
          fieldId: "role",
        }],
        ["text-input", {
          label: __("Organization", "gpt3-ai-content-generator"),
          placeholder: __("Company or project", "gpt3-ai-content-generator"),
          fieldId: "organization",
        }],
        ["textarea", {
          label: __("Why are you interested?", "gpt3-ai-content-generator"),
          placeholder: __("Tell us what caught your attention", "gpt3-ai-content-generator"),
          fieldId: "interest",
          required: true,
        }],
      ]
    ),
  };

  function aipkitForms_getTemplateDraft(templateKey) {
    if (!Object.prototype.hasOwnProperty.call(templates, templateKey)) return null;
    const __ = typeof wp !== "undefined" && wp.i18n && wp.i18n.__
      ? wp.i18n.__ : (text) => text;
    return templates[templateKey](__);
  }

  window.aipkitForms_getTemplateDraft = aipkitForms_getTemplateDraft;
})();
