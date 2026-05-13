import app from "flarum/admin/app";

import installGlobalErrorHandler from "../common/utils/installGlobalErrorHandler";
import addHideExtensionSubmitButton from "./addHideExtensionSubmitButton";
import addVerifiedSettingsPanel from "./addVerifiedSettingsPanel";

export { default as extend } from "../common/extend";

addHideExtensionSubmitButton();

app.initializers.add("ramon-verified", () => {
  installGlobalErrorHandler();
  addVerifiedSettingsPanel();
});
