import Model from "flarum/common/Model";
import type User from "flarum/common/models/User";

export type VerificationRequestStatus = "pending" | "approved" | "rejected";

export default class VerificationRequest extends Model {
  status() {
    return Model.attribute<VerificationRequestStatus>("status").call(this);
  }
  documentType() {
    return Model.attribute<string | null>("documentType").call(this);
  }
  documentPath() {
    return Model.attribute<string | null>("documentPath").call(this);
  }
  reason() {
    return Model.attribute<string | null>("reason").call(this);
  }
  adminNote() {
    return Model.attribute<string | null>("adminNote").call(this);
  }
  createdAt() {
    return Model.attribute<Date | null, string | null>(
      "createdAt",
      Model.transformDate
    ).call(this);
  }
  updatedAt() {
    return Model.attribute<Date | null, string | null>(
      "updatedAt",
      Model.transformDate
    ).call(this);
  }
  handledAt() {
    return Model.attribute<Date | null, string | null>(
      "handledAt",
      Model.transformDate
    ).call(this);
  }
  user() {
    return Model.hasOne<User | null>("user").call(this);
  }
  handler() {
    return Model.hasOne<User | null>("handler").call(this);
  }

  apiEndpoint(): string {
    return (
      "/verification-requests" + ("id" in this.data ? "/" + this.data.id : "")
    );
  }

  isPending(): boolean {
    return this.status() === "pending";
  }

  isApproved(): boolean {
    return this.status() === "approved";
  }

  isRejected(): boolean {
    return this.status() === "rejected";
  }
}
