terraform {
  backend "s3" {
    bucket         = "schuit-info-terraform-state"
    key            = "environments/prod/terraform.tfstate"
    region         = "eu-west-1"
    dynamodb_table = "schuit-info-terraform-locks"
    encrypt        = true
  }
}
